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

    /**
     * Submit a `computed` run for approval. Only super-admin in v1.
     */
    public function submit(User $user, PayrollRun $run): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES)
            && $run->status === PayrollRun::STATUS_COMPUTED;
    }

    /**
     * Approve a `pending_approval` run. Only super-admin in v1; widening
     * to a payroll-officer "approver" role lands later.
     */
    public function approve(User $user, PayrollRun $run): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES)
            && $run->status === PayrollRun::STATUS_PENDING_APPROVAL;
    }

    /**
     * Post an `approved` run. Final transition; only super-admin.
     */
    public function post(User $user, PayrollRun $run): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES)
            && $run->status === PayrollRun::STATUS_APPROVED;
    }

    /**
     * Void any non-posted, non-voided run.
     */
    public function void(User $user, PayrollRun $run): bool
    {
        if (! $user->hasAnyRole(self::SUPER_ADMIN_ROLES)) {
            return false;
        }

        return $run->status !== PayrollRun::STATUS_POSTED
            && $run->status !== PayrollRun::STATUS_VOIDED;
    }
}
