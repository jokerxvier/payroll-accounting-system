<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\EmployeeAllowance;
use App\Models\User;

/**
 * Authorization for per-employee allowance subscriptions.
 *
 * Same role matrix as `EmployeeDeductionPolicy`: super-admin / payroll-officer
 * / hr manage; an `employee` can view their own row; only super-admin can
 * delete (defense-in-depth for the audit trail — close via `effective_to`
 * instead).
 */
final class EmployeeAllowancePolicy
{
    /** @var list<string> */
    private const MANAGE_ROLES = ['super-admin', 'payroll-officer', 'hr'];

    /** @var list<string> */
    private const DELETE_ROLES = ['super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function view(User $user, EmployeeAllowance $row): bool
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

    public function update(User $user, EmployeeAllowance $row): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function delete(User $user, EmployeeAllowance $row): bool
    {
        return $user->hasAnyRole(self::DELETE_ROLES);
    }
}
