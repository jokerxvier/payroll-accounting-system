<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null): bool {
            if ($user === null) {
                return false;
            }

            // Payroll-native operators (platform admins) own queue health.
            if (method_exists($user, 'hasRole') && $user->hasRole('platform-admin')) {
                return true;
            }

            // Escape hatch: explicit allowlist via HORIZON_DASHBOARD_EMAILS
            // (comma-separated) for ops staff without the platform-admin role.
            $allowlist = array_filter(array_map(
                'trim',
                explode(',', (string) config('horizon.dashboard_emails', '')),
            ));

            return in_array($user->email, $allowlist, true);
        });
    }
}
