<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\Allowance;
use App\Models\User;

/**
 * Authorization for the allowance catalog admin surface.
 *
 * v1 scope: only super-admin manages the catalog. Mirrors
 * `StatutoryContributionPolicy`. Per-employee subscriptions to an allowance
 * live on `pas_employee_allowances` and are gated separately by
 * `EmployeeAllowancePolicy` (HR + payroll-officer + super-admin).
 */
final class AllowancePolicy
{
    /** @var list<string> */
    private const SUPER_ADMIN_ROLES = ['super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function update(User $user, Allowance $row): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function delete(User $user, Allowance $row): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }
}
