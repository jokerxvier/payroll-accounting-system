<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\PayrollAdjustment;
use App\Models\User;

/**
 * Authorization for one-off payroll adjustments.
 *
 * Same role matrix as the other per-employee Pas policies: super-admin /
 * payroll-officer / hr manage (including delete); an `employee` can view
 * their own adjustment but cannot delete it. Adjustments that have already
 * shipped on a payroll run should be corrected with a reversing entry, but
 * managers retain delete as the escape hatch for genuinely erroneous rows
 * that have not yet been included in a run.
 */
final class PayrollAdjustmentPolicy
{
    /** @var list<string> */
    private const MANAGE_ROLES = ['super-admin', 'payroll-officer', 'hr'];

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
        return $user->hasAnyRole(self::MANAGE_ROLES);
    }
}
