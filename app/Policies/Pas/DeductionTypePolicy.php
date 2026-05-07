<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\DeductionType;
use App\Models\User;

/**
 * Authorization for the deduction-type catalog admin surface.
 *
 * Open to super-admin, payroll-officer, and hr — all three manage the
 * catalog (create/update/delete) since deduction composition is a daily
 * payroll-ops task, not a system-config one. Per-employee subscriptions to
 * a deduction type live on `pas_employee_deductions` and are gated
 * separately by `EmployeeDeductionPolicy`.
 */
final class DeductionTypePolicy
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

    public function update(User $user, DeductionType $row): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function delete(User $user, DeductionType $row): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }
}
