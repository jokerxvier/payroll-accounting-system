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
 * Delete mirrors the create/update envelope: super-admin, payroll-officer,
 * and hr may all destroy a row. End-dating via `effective_to` is still the
 * preferred close-out path because it preserves history for back-dated
 * payslips, but managers who can create a row can also remove an outright
 * mistake. The owning employee cannot delete their own row even though they
 * can view it.
 */
final class EmployeeDeductionPolicy
{
    /** @var list<string> */
    private const MANAGE_ROLES = ['super-admin', 'payroll-officer', 'hr'];

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
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }
}
