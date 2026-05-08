<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Lms\User as LmsUser;
use App\Models\User;
use App\Services\Auth\UpsertPasUserFromLms;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Throwable;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthentication();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        // Password reset is intentionally NOT registered. Phase A.2 of the
        // multi-tenant refactor (docs/improvement/plan-2.md, "Open question —
        // password resets" → option (b)) makes the LMS the identity master;
        // password resets are handled out-of-band by the LMS admin. The
        // app/Actions/Fortify/ResetUserPassword.php file is retained
        // (orphan but harmless) for the eventual cleanup PR.
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        // Password reset (Fortify resetPasswords feature) is disabled in
        // Phase A.2 — users reset via their LMS admin since LMS is the
        // identity master. The login view no longer surfaces a "Forgot
        // password?" link, so `canResetPassword` is no longer passed.
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canRegister' => Features::enabled(Features::registration()),
            'status' => $request->session()->get('status'),
            'showDemoLogin' => ! app()->isProduction(),
        ]));

        // Password reset views are intentionally not registered (LMS is
        // identity master; resets are out-of-band per Phase A.2 decision).

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }

    /**
     * Configure the Fortify authentication callback.
     *
     * Phase A.2 swap: the LMS is the identity master. Fortify's default
     * model-password lookup is replaced with a callback that:
     *
     *   1. Looks up the LMS user by email (read-only `lms` connection).
     *   2. Verifies the supplied password against the LMS's bcrypt hash.
     *   3. Upserts a row in `pas_users` (id-preserved from the LMS row) so
     *      the rest of Fortify (session continuity, password confirmation,
     *      2FA, email verification) operates on the payroll-DB-owned table.
     *   4. Returns the resolved `App\Models\User` so Fortify can log it in.
     *
     * The defensive try/catch on the LMS read mirrors the pattern in
     * `EloquentEmployeeRepository::countActiveStaff()`, etc. — an
     * unreachable LMS degrades to "credentials don't match" UX rather than
     * a 500.
     *
     * Payroll-native fallback: when the LMS lookup returns null (no LMS user
     * with that email exists), try a second lookup against pas_users where
     * lms_user_id IS NULL. This is the platform-admin login path — payroll-
     * native operators who manage the multi-school deployment have no LMS
     * counterpart. The fallback is gated on an LMS lookup that returned null
     * cleanly; if the LMS is unreachable (Throwable), we fail fast rather
     * than fall through, so a transient LMS outage cannot accidentally
     * authenticate a tenant-scoped user via the payroll-native path. The
     * `lms_user_id IS NULL` filter is the load-bearing guard — it ensures
     * only payroll-native rows can reach this verification, never an LMS-
     * derived user whose lms_user_id was somehow nulled in the row.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request) {
            $email = (string) $request->input('email', '');
            $password = (string) $request->input('password', '');

            if ($email === '' || $password === '') {
                return null;
            }

            try {
                $lmsUser = LmsUser::query()->where('email', $email)->first();
            } catch (Throwable $e) {
                // LMS unreachable — login fails gracefully (same UX as
                // wrong credentials). Logged for ops via report(). The
                // payroll-native fallback intentionally does NOT run here:
                // a transient LMS outage must not become an alternate auth
                // path for LMS-derived users (whose pas_users rows have
                // lms_user_id NOT NULL anyway, so they wouldn't match —
                // but defense in depth).
                report($e);

                return null;
            }

            if ($lmsUser !== null && Hash::check($password, (string) $lmsUser->password)) {
                return app(UpsertPasUserFromLms::class)($lmsUser);
            }

            // Payroll-native fallback. Only fires when the LMS lookup
            // succeeded (no Throwable above) but found no row for that
            // email. Verifies against pas_users.password directly for rows
            // with lms_user_id IS NULL.
            if ($lmsUser === null) {
                $nativeUser = User::query()
                    ->where('email', $email)
                    ->whereNull('lms_user_id')
                    ->first();

                if ($nativeUser !== null && Hash::check($password, (string) $nativeUser->getAuthPassword())) {
                    return $nativeUser;
                }
            }

            return null;
        });
    }
}
