<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the payroll roles per PLAN.md Week 2.
 *
 * Idempotent — running the seeder multiple times will not create duplicates.
 * Roles are guarded under 'web' (the Fortify guard).
 *
 * Permissions themselves are not seeded here; they are added in later phases
 * as features ship (e.g. payroll.run.create, ledger.post). This seeder only
 * establishes the role taxonomy.
 *
 * Role taxonomy:
 *  - platform-admin    — payroll-native cross-tenant operator (no LMS counterpart).
 *                        Holds the school switcher and /admin/schools registry.
 *                        Only assignable to pas_users rows where lms_user_id IS NULL.
 *  - super-admin       — school-scoped admin originating from an LMS. Approves
 *                        payroll runs, manages catalog within their school. NO
 *                        cross-tenant powers.
 *  - payroll-officer / hr / auditor / employee — LMS-derived, school-scoped.
 *  - accountant        — Phase 5 ledger operator. Seeded but NOT yet reachable:
 *                        no LMS role maps to it in config/payroll.php, and
 *                        AssignPayrollRoleOnLogin re-applies that mapping with
 *                        syncRoles on every login, so a hand-assigned role is
 *                        stripped at next sign-in. Remapping LMS roles 6
 *                        (Accountant) / 13 (Accounts/Finance Officer) here
 *                        would revoke their payroll access, since syncRoles
 *                        replaces rather than adds — that trade-off is the
 *                        client's call (Open Question 3 in the Phase 5 plan).
 *                        Until then App\Policies\Pas\AccountingRoles also
 *                        allowlists payroll-officer so the module is usable.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'platform-admin',
            'super-admin',
            'payroll-officer',
            'accountant',
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
