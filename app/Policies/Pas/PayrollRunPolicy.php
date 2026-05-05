<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\PayrollRun;
use App\Models\User;

/**
 * Authorization for the payroll-runs admin surface.
 *
 * v1 scope (Phase 3 Week 9): only super-admin can list/view/create runs.
 * Approval (`approve`) and posting (`post`) are reserved for Week 10 when
 * the policy will widen to payroll-officer + an approval-specific role gate.
 *
 * Voiding follows the project's "void don't delete" convention — the action
 * is in this policy but only legal while the run is still draft/computed
 * (`PayrollRun::isMutable()`). Once approved/posted the run is frozen.
 */
final class PayrollRunPolicy
{
    /** @var list<string> */
    private const SUPER_ADMIN_ROLES = ['super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function void(User $user, PayrollRun $run): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES) && $run->isMutable();
    }
}
