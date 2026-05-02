<?php

namespace App\Providers;

use App\Listeners\AssignPayrollRoleOnLogin;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\StatutoryContribution;
use App\Policies\Pas\EmployeeProfilePolicy;
use App\Policies\Pas\StatutoryContributionPolicy;
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
