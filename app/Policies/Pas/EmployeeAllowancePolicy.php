<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\EmployeeAllowance;
use App\Models\User;

/**
 * Authorization for per-employee allowance subscriptions.
 *
 * Same role matrix as `EmployeeDeductionPolicy`: super-admin / payroll-officer
 * / hr manage (including delete); an `employee` can view their own row but
 * cannot delete it. End-dating via `effective_to` remains the preferred
 * close-out path because it preserves history for back-dated payslips.
 */
final class EmployeeAllowancePolicy
{
    /** @var list<string> */
    private const MANAGE_ROLES = ['super-admin', 'payroll-officer', 'hr'];

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
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }
}
