<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\PayrollRun;
use App\Models\User;

/**
 * Authorization for the payroll-runs admin surface.
 *
 * Maker-checker split: payroll-officer + hr (the "makers") create and submit
 * runs; super-admin (the "approver") owns approve, post, and void. View and
 * list are open to all makers + the approver so officers can see the runs
 * they kicked through approval.
 *
 * Voiding follows the project's "void don't delete" convention — the action
 * is in this policy but only legal while the run is still pre-posted.
 * Once posted the run is frozen.
 */
final class PayrollRunPolicy
{
    /** @var list<string> */
    private const MAKER_ROLES = ['super-admin', 'payroll-officer', 'hr'];

    /** @var list<string> */
    private const APPROVAL_ROLES = ['super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::MAKER_ROLES);
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $user->hasAnyRole(self::MAKER_ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::MAKER_ROLES);
    }

    /**
     * Submit a `computed` run for approval. Maker action.
     */
    public function submit(User $user, PayrollRun $run): bool
    {
        return $user->hasAnyRole(self::MAKER_ROLES) && $run->isSubmittable();
    }

    /**
     * Approve a `pending_approval` run. Approver action.
     */
    public function approve(User $user, PayrollRun $run): bool
    {
        return $user->hasAnyRole(self::APPROVAL_ROLES) && $run->isApprovable();
    }

    /**
     * Post an `approved` run. Final transition; approver action.
     */
    public function post(User $user, PayrollRun $run): bool
    {
        return $user->hasAnyRole(self::APPROVAL_ROLES) && $run->isPostable();
    }

    /**
     * Void any non-posted, non-voided run. Approver action.
     */
    public function void(User $user, PayrollRun $run): bool
    {
        return $user->hasAnyRole(self::APPROVAL_ROLES) && $run->isVoidable();
    }

    /**
     * Hard-delete a run. DEMO affordance — breaks the "void, don't delete"
     * convention on purpose. Gated to makers; allowed at any status so the
     * demo can wipe a run in one action.
     */
    public function delete(User $user, PayrollRun $run): bool
    {
        return $user->hasAnyRole(self::MAKER_ROLES);
    }
}
