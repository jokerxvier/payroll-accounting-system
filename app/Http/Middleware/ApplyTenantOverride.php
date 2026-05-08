<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Pas\School;
use Closure;
use Illuminate\Http\Request;
use Spatie\Multitenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-pivots the active tenant based on the authenticated user's origin.
 *
 * Why this is a separate middleware instead of part of the SchoolTenantFinder:
 * Spatie's MultitenancyServiceProvider calls `Multitenancy->start()` during
 * the boot phase — before any middleware runs. At that point the session
 * hasn't been started, `$request->session()` throws, and `Auth::user()` is
 * unavailable. So the boot-time finder runs first and resolves a tenant via
 * subdomain/path/header. THIS middleware then runs after StartSession and
 * after Auth, reads the user's origin (LMS-derived vs payroll-native) and
 * any session-stored override, and rebinds the active tenant if needed.
 *
 * Place this in the web middleware group after session/auth-resolving
 * middleware and before any controller code that reads tenant-scoped data.
 *
 * Two strategies, gated by user origin (lms_user_id is the discriminator):
 *
 *   Strategy 1 — LMS-pinning (lms_user_id NOT NULL).
 *     Every LMS-derived user is locked to their `school_id` regardless of
 *     role. This catches super-admin / hr / payroll-officer / auditor /
 *     employee — they all originated in one specific LMS and have no
 *     business operating cross-tenant. Even a crafted X-School-Slug header
 *     or path prefix that pivots the boot-time finder cannot survive this
 *     middleware.
 *
 *   Strategy 2 — Platform-admin session override (lms_user_id NULL +
 *     `platform-admin` role). Payroll-native operators read the in-app
 *     switcher's session-stored override id and pivot to that school. This
 *     is the ONLY path that grants cross-tenant powers, and it's bounded
 *     by both the NULL lms_user_id and the explicit Spatie role.
 *
 * Security:
 *   - LMS-derived users (lms_user_id NOT NULL) NEVER read the override; a
 *     leaked session key on their session is ignored.
 *   - Payroll-native users without the `platform-admin` role NEVER read the
 *     override either. The role gate AND the lms_user_id gate are both
 *     required.
 *   - The override is read from the session, which is signed/encrypted by
 *     Laravel's default cookie pipeline, not from a raw cookie.
 *   - Inactive schools are never resolved (see pivotTo()).
 */
final class ApplyTenantOverride
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Strategy 1 — LMS-pinning. Every LMS-derived user (lms_user_id
        // NOT NULL) is locked to their school_id regardless of role.
        // school_id is stamped onto pas_users on every successful login by
        // UpsertPasUserFromLms.
        if ($user->getAttribute('lms_user_id') !== null) {
            $userSchoolId = $user->getAttribute('school_id');
            if (is_int($userSchoolId) || (is_string($userSchoolId) && ctype_digit($userSchoolId))) {
                $this->pivotTo((int) $userSchoolId);
            }

            return $next($request);
        }

        // Strategy 2 — Platform-admin session override. Only payroll-native
        // users (lms_user_id IS NULL) holding the platform-admin role can
        // pivot via the in-app switcher. Both gates must pass, so an
        // accidentally role-stripped platform admin cannot pivot, and a
        // leaked session override on a non-platform-admin payroll-native
        // user (none exist today, but defense in depth) is ignored.
        $isPlatformAdmin = method_exists($user, 'hasRole')
            && $user->hasRole('platform-admin');

        if (! $isPlatformAdmin) {
            return $next($request);
        }

        if (! $request->hasSession()) {
            return $next($request);
        }

        $overrideId = $request->session()->get('current_school_id_override');
        if (! is_int($overrideId) && ! (is_string($overrideId) && ctype_digit($overrideId))) {
            return $next($request);
        }

        $this->pivotTo((int) $overrideId);

        return $next($request);
    }

    /**
     * Re-pivot the active tenant to the given school id when it differs from
     * the currently-resolved tenant. Inactive schools and missing rows are
     * silently skipped (the existing tenant stays current), which is the
     * conservative choice for the non-super-admin path: we never accidentally
     * downgrade a logged-in user to a "no tenant" state mid-request.
     */
    private function pivotTo(int $schoolId): void
    {
        $school = School::query()
            ->whereKey($schoolId)
            ->where('is_active', true)
            ->first();
        if ($school === null) {
            return;
        }

        $current = Tenant::current();
        if ($current && $current->getKey() === $school->getKey()) {
            return;
        }

        if ($current) {
            Tenant::forgetCurrent();
        }
        $school->makeCurrent();
    }
}
