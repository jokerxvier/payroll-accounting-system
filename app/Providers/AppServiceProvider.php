<?php

namespace App\Providers;

use App\Listeners\AssignPayrollRoleOnLogin;
use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollAdjustment;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\School;
use App\Models\Pas\StatutoryContribution;
use App\Policies\Pas\AllowancePolicy;
use App\Policies\Pas\DeductionTypePolicy;
use App\Policies\Pas\EmployeeAllowancePolicy;
use App\Policies\Pas\EmployeeDeductionPolicy;
use App\Policies\Pas\EmployeeLoanPolicy;
use App\Policies\Pas\EmployeeProfilePolicy;
use App\Policies\Pas\PayPeriodPolicy;
use App\Policies\Pas\PayrollAdjustmentPolicy;
use App\Policies\Pas\PayrollRunPolicy;
use App\Policies\Pas\SchoolPolicy;
use App\Policies\Pas\StatutoryContributionPolicy;
use App\Policies\PayrollPreviewPolicy;
use App\Services\Statutory\StatutoryContributionResolver;
use App\Services\Statutory\Strategies\BracketTableStrategy;
use App\Services\Statutory\Strategies\FlatPercentageStrategy;
use App\Services\Statutory\Strategies\PercentageWithCapStrategy;
use App\Services\Statutory\Strategies\SalaryBandStrategy;
use App\Services\Statutory\Strategies\TieredPercentageStrategy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            StatutoryContributionResolver::class,
            fn (): StatutoryContributionResolver => new StatutoryContributionResolver([
                StatutoryContribution::ALGORITHM_BRACKET_TABLE => new BracketTableStrategy,
                StatutoryContribution::ALGORITHM_SALARY_BAND => new SalaryBandStrategy,
                StatutoryContribution::ALGORITHM_PERCENTAGE_WITH_CAP => new PercentageWithCapStrategy,
                StatutoryContribution::ALGORITHM_TIERED_PERCENTAGE => new TieredPercentageStrategy,
                StatutoryContribution::ALGORITHM_FLAT_PERCENTAGE => new FlatPercentageStrategy,
            ]),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerEventListeners();
        $this->registerPolicies();
        $this->registerPlatformAdminGate();
    }

    /**
     * Short-circuit every Gate / policy check for the `platform-admin` role.
     *
     * Platform admins are payroll-native operators (lms_user_id IS NULL) who
     * manage the multi-school deployment. Within whichever tenant they've
     * switched to via the in-app dropdown, they should be able to do anything
     * — view employees, manage payroll runs, edit catalog, audit logs, etc.
     * Per-policy role lists exist for the LMS-derived role hierarchy
     * (super-admin / payroll-officer / hr / auditor / employee); the
     * platform-admin sits above that hierarchy as the cross-tenant operator.
     *
     * The Gate::before short-circuit means a single check at the gate level
     * grants every ability without each policy individually listing
     * `platform-admin` in its allowlist. Policies still own the
     * lower-precedence role logic for everyone else.
     *
     * Returning `null` (not `false`) lets the policy fall through to its
     * normal evaluation when the user is not platform-admin.
     */
    protected function registerPlatformAdminGate(): void
    {
        Gate::before(function ($user, string $ability): ?bool {
            if (! method_exists($user, 'hasRole')) {
                return null;
            }

            // Defense in depth: only PAYROLL-NATIVE users (lms_user_id NULL)
            // with the platform-admin role get the short-circuit. An
            // LMS-derived user that somehow has the platform-admin role
            // assigned (shouldn't happen but possible if seeders are
            // misconfigured) falls through to per-policy evaluation, which
            // SchoolPolicy further gates by the same lms_user_id IS NULL
            // check. Both layers must agree.
            $isPayrollNative = $user->getAttribute('lms_user_id') === null;

            if ($isPayrollNative && $user->hasRole('platform-admin')) {
                return true;
            }

            return null;
        });
    }

    /**
     * Explicitly register policies for app-owned models living under the
     * `App\Models\Pas` sub-namespace. Laravel's auto-discovery only checks
     * `App\Policies\<ModelName>Policy` — it does not mirror sub-namespaces
     * — so policies under `App\Policies\Pas` must be wired here.
     */
    protected function registerPolicies(): void
    {
        Gate::policy(EmployeeProfile::class, EmployeeProfilePolicy::class);
        Gate::policy(StatutoryContribution::class, StatutoryContributionPolicy::class);

        // Week 7 — deductions, loans, allowances, adjustments
        Gate::policy(DeductionType::class, DeductionTypePolicy::class);
        Gate::policy(Allowance::class, AllowancePolicy::class);
        Gate::policy(EmployeeDeduction::class, EmployeeDeductionPolicy::class);
        Gate::policy(EmployeeAllowance::class, EmployeeAllowancePolicy::class);
        Gate::policy(EmployeeLoan::class, EmployeeLoanPolicy::class);
        Gate::policy(PayrollAdjustment::class, PayrollAdjustmentPolicy::class);

        // Phase 3 Week 9 — pay periods + payroll runs (admin batch generation).
        Gate::policy(PayPeriod::class, PayPeriodPolicy::class);
        Gate::policy(PayrollRun::class, PayrollRunPolicy::class);

        // Phase B.2 — multi-tenant schools registry. Super-admin only via
        // SchoolPolicy's before() hook.
        Gate::policy(School::class, SchoolPolicy::class);

        // Week 8 — real-time gross-to-net preview. Class-level Gate (no
        // underlying model), so it lives outside Gate::policy() registrations.
        Gate::define('payroll.preview', [PayrollPreviewPolicy::class, 'preview']);

        // Phase 3 W9 — dev/demo affordance. Super-admin only AND only when
        // not running in production. The controller adds its own
        // `app()->environment('production')` guard for defence in depth,
        // but gating here keeps the button hidden in the admin UI.
        Gate::define(
            'dev.seed-demo-salaries',
            fn ($user): bool => $user->hasRole('super-admin') && ! app()->environment('production'),
        );
    }

    /**
     * Register domain event listeners.
     */
    protected function registerEventListeners(): void
    {
        Event::listen(Login::class, AssignPayrollRoleOnLogin::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
