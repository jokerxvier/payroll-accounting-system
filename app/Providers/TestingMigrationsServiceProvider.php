<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the test-scoped `users` migration in the testing environment.
 *
 * The production `users` table is owned by the LMS and read-only at this
 * app's boundary. database/migrations/testing/ contains a sqlite-compatible
 * stand-in so RefreshDatabase + the auth tests have a `users` table to
 * INSERT into without touching the real LMS schema.
 *
 * Migrations under database/migrations/testing/ are deliberately NOT under
 * database/migrations/ so that MigrationSafetyTest's pas_-prefix allowlist
 * does not flag them.
 */
final class TestingMigrationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(database_path('migrations/testing'));
        }
    }
}
