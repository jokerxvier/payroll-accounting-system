<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * ## Production-safe (always runs)
     *
     *   RoleSeeder
     *   PlatformAdminSeeder        → admin@payroll.test / password (rotate before non-dev traffic)
     *   SchoolSeeder               → default tenant (slug=default)
     *   StatutoryContributionSeeder
     *   Week7CatalogSeeder
     *
     * ## Local-only (APP_ENV=local)
     *
     *   DemoUsersSeeder     ✓ safe  — writes pas_users; school_id is nullable on
     *                                 pas_users by design (platform admins).
     *   DemoCatalogSeeder   ✗ BROKEN as of 2026-06-09. Writes to Allowance +
     *                                 DeductionType (both BelongsToTenant, both
     *                                 per-tenant catalogs). Tenant::current() is
     *                                 null in seeder context, so school_id stays
     *                                 null and the NOT NULL constraint rejects.
     *                                 Fix: wrap inserts in Tenant::setCurrent()
     *                                 or pass school_id explicitly.
     *   DemoPayrollSeeder   ✗ BROKEN — same root cause. Writes to EmployeeProfile,
     *                                 PayPeriod, PayrollRun, Payslip,
     *                                 EmployeeAllowance, EmployeeDeduction.
     *
     * ## Recommended dev run (skip the broken pair until fixed)
     *
     *   php artisan db:seed --class=RoleSeeder
     *   php artisan db:seed --class=PlatformAdminSeeder
     *   php artisan db:seed --class=SchoolSeeder
     *   php artisan db:seed --class=StatutoryContributionSeeder
     *   php artisan db:seed --class=Week7CatalogSeeder
     *   php artisan db:seed --class=DemoUsersSeeder
     *
     * Bare `php artisan db:seed` is currently NOT recommended on APP_ENV=local
     * because it will fail at DemoCatalogSeeder.
     *
     * All seeders above are idempotent — re-running is safe.
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
