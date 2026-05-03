<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\EmployeeDeduction;
use App\Models\User;

/**
 * Authorization for per-employee custom deductions.
 *
 * Read + write allowlist: super-admin, payroll-officer, hr — the roles that
 * staff payroll operations day-to-day. Auditor is not on the allowlist; they
 * read through aggregate report endpoints, not per-employee subscription
 * tables.
 *
 * Self-service carve-out: an `employee` whose payroll profile owns the row
 * may `view()` it. They cannot create, update, or delete — those are
 * payroll-team operations.
 *
 * Delete is super-admin only as a defense-in-depth check on the audit
 * trail. HR and payroll-officer should close subscriptions by setting
 * `effective_to` (which preserves history for back-dated payslips), not by
 * deleting the row outright.
 */
final class EmployeeDeductionPolicy
{
    /** @var list<string> */
    private const MANAGE_ROLES = ['super-admin', 'payroll-officer', 'hr'];

    /** @var list<string> */
    private const DELETE_ROLES = ['super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function view(User $user, EmployeeDeduction $row): bool
    {
        if ($user->hasAnyRole(self::MANAGE_ROLES)) {
            return true;
        }

        return $row->employee_profile_id === $user->employeeProfile?->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function update(User $user, EmployeeDeduction $row): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function delete(User $user, EmployeeDeduction $row): bool
    {
        return $user->hasAnyRole(self::DELETE_ROLES);
    }
}
