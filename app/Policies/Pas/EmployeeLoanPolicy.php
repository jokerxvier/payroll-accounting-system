<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\EmployeeLoan;
use App\Models\User;

/**
 * Authorization for per-employee loans.
 *
 * Same role matrix as the other per-employee Pas policies: super-admin /
 * payroll-officer / hr manage (including delete); an `employee` can view
 * their own loan but cannot delete it. Closed loans are typically kept (the
 * table is the historical record), so `delete` is rare — managers should
 * mark a loan closed via `applyAmortization()` to zero rather than removing
 * the row, and use destroy only for genuinely erroneous entries.
 */
final class EmployeeLoanPolicy
{
    /** @var list<string> */
    private const MANAGE_ROLES = ['super-admin', 'payroll-officer', 'hr'];

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
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }
}
