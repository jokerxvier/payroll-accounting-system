<?php

namespace App\Providers;

use App\Listeners\AssignPayrollRoleOnLogin;
use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
use App\Models\Pas\StatutoryContribution;
use App\Policies\Pas\AllowancePolicy;
use App\Policies\Pas\DeductionTypePolicy;
use App\Policies\Pas\EmployeeAllowancePolicy;
use App\Policies\Pas\EmployeeDeductionPolicy;
use App\Policies\Pas\EmployeeLoanPolicy;
use App\Policies\Pas\EmployeeProfilePolicy;
use App\Policies\Pas\PayrollAdjustmentPolicy;
use App\Policies\Pas\StatutoryContributionPolicy;
use App\Policies\PayrollPreviewPolicy;
use App\Services\Statutory\StatutoryContributionResolver;
use App\Services\Statutory\Strategies\BracketTableStrategy;
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

        // Week 8 — real-time gross-to-net preview. Class-level Gate (no
        // underlying model), so it lives outside Gate::policy() registrations.
        Gate::define('payroll.preview', [PayrollPreviewPolicy::class, 'preview']);
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
