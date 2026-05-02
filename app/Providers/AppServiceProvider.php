<?php

namespace App\Providers;

use App\Listeners\AssignPayrollRoleOnLogin;
use App\Models\Pas\EmployeeProfile;
use App\Policies\Pas\EmployeeProfilePolicy;
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
        //
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
