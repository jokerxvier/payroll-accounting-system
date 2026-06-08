<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Production-safe seeders always run (role taxonomy + statutory rate
     * tables + W7 catalog). Demo seeders (users, expanded catalog, sample
     * payroll runs) only run in `local` — never in staging or production.
     *
     * On staging, invoke demo seeders explicitly when needed:
     *
     *     php artisan db:seed --class=DemoUsersSeeder
     *     php artisan db:seed --class=DemoCatalogSeeder
     *     php artisan db:seed --class=DemoPayrollSeeder
     *
     * Each demo seeder is idempotent so re-running is safe.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            // PlatformAdminSeeder runs after RoleSeeder so the
            // 'platform-admin' role exists before assignment, and it's
            // production-safe because the row is keyed on a stable email
            // (idempotent updateOrInsert).
            PlatformAdminSeeder::class,
            SchoolSeeder::class,
            StatutoryContributionSeeder::class,
            Week7CatalogSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoUsersSeeder::class);
            $this->call(DemoCatalogSeeder::class);
            $this->call(DemoPayrollSeeder::class);
        }
    }
}
