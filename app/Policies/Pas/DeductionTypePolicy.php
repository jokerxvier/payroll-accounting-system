<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\DeductionType;
use App\Models\User;

/**
 * Authorization for the deduction-type catalog admin surface.
 *
 * v1 scope: only super-admin manages the catalog. Mirrors
 * `StatutoryContributionPolicy`. Per-employee subscriptions to a deduction
 * type live on `pas_employee_deductions` and are gated separately by
 * `EmployeeDeductionPolicy` (HR + payroll-officer + super-admin).
 */
final class DeductionTypePolicy
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

    public function update(User $user, DeductionType $row): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function delete(User $user, DeductionType $row): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }
}
