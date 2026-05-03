<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\EmployeeLoan;
use App\Models\User;

/**
 * Authorization for per-employee loans.
 *
 * Same role matrix as the other per-employee Pas policies: super-admin /
 * payroll-officer / hr manage; an `employee` can view their own loan; only
 * super-admin can delete. Closed loans are kept (the table is the historical
 * record), so `delete` is rare — payroll-officer should mark a loan closed
 * via `applyAmortization()` to zero rather than removing the row.
 */
final class EmployeeLoanPolicy
{
    /** @var list<string> */
    private const MANAGE_ROLES = ['super-admin', 'payroll-officer', 'hr'];

    /** @var list<string> */
    private const DELETE_ROLES = ['super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function view(User $user, EmployeeLoan $row): bool
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

    public function update(User $user, EmployeeLoan $row): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function delete(User $user, EmployeeLoan $row): bool
    {
        return $user->hasAnyRole(self::DELETE_ROLES);
    }
}
