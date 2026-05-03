<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\PayrollAdjustment;
use App\Models\User;

/**
 * Authorization for one-off payroll adjustments.
 *
 * Same role matrix as the other per-employee Pas policies: super-admin /
 * payroll-officer / hr manage; an `employee` can view their own adjustment;
 * only super-admin can delete. Adjustments are typically corrected with a
 * reversing entry rather than deleted, but super-admin retains the escape
 * hatch for genuinely erroneous rows that have not yet been included in a
 * payroll run.
 */
final class PayrollAdjustmentPolicy
{
    /** @var list<string> */
    private const MANAGE_ROLES = ['super-admin', 'payroll-officer', 'hr'];

    /** @var list<string> */
    private const DELETE_ROLES = ['super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function view(User $user, PayrollAdjustment $row): bool
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

    public function update(User $user, PayrollAdjustment $row): bool
    {
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }

    public function delete(User $user, PayrollAdjustment $row): bool
    {
        return $user->hasAnyRole(self::DELETE_ROLES);
    }
}
