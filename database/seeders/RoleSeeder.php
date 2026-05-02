<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the five payroll roles per PLAN.md Week 2.
 *
 * Idempotent — running the seeder multiple times will not create duplicates.
 * Roles are guarded under 'web' (the Fortify guard).
 *
 * Permissions themselves are not seeded here; they are added in later phases
 * as features ship (e.g. payroll.run.create, ledger.post). This seeder only
 * establishes the role taxonomy.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'super-admin',
            'payroll-officer',
            'hr',
            'auditor',
            'employee',
        ];

        foreach ($roles as $name) {
            Role::query()->firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
            );
        }

        // Force the permission cache to refresh so role lookups in the same
        // process pick up the freshly seeded roles immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
