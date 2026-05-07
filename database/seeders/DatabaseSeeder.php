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
     * payroll runs) only run in `local` and `staging` — never in production.
     *
     * `DemoPayrollSeeder` transitively calls `DemoCatalogSeeder` and
     * `DemoUsersSeeder`, so a single call here pulls in all three demo
     * tiers. Each demo seeder is idempotent so re-running `db:seed` is safe.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            StatutoryContributionSeeder::class,
            Week7CatalogSeeder::class,
        ]);

        if (app()->environment('local', 'staging')) {
            $this->call(DemoPayrollSeeder::class);
        }
    }
}
