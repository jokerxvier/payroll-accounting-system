<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\Allowance;
use App\Models\User;

/**
 * Authorization for the allowance catalog admin surface.
 *
 * Open to super-admin, payroll-officer, and hr — all three manage the
 * catalog (create/update/delete) since allowance composition is a daily
 * payroll-ops task, not a system-config one. Per-employee subscriptions to
 * an allowance live on `pas_employee_allowances` and are gated separately
 * by `EmployeeAllowancePolicy`.
 */
final class AllowancePolicy
{
    /** @var list<string> */
    private const MANAGE_ROLES = ['super-admin', 'payroll-officer', 'hr'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function update(User $user, Allowance $row): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function delete(User $user, Allowance $row): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }
}
