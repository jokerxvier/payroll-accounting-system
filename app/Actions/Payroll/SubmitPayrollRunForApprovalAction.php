<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\PayrollRun;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * `computed → pending_approval`. Stamps `submitted_at` + the actor.
 *
 * Authorization (super-admin AND status === computed) is the controller's
 * responsibility via PayrollRunPolicy::submit. This action enforces the
 * status invariant defensively so a misuse from a job/console path can't
 * silently advance an inappropriate run.
 */
final class SubmitPayrollRunForApprovalAction
{
    public function execute(PayrollRun $run, int $actorUserId): PayrollRun
    {
        if ($run->status !== PayrollRun::STATUS_COMPUTED) {
            throw new DomainException(sprintf(
                'Cannot submit a payroll run for approval from status [%s]. Expected [computed].',
                $run->status,
            ));
        }

        return DB::transaction(function () use ($run, $actorUserId): PayrollRun {
            $run->forceFill([
                'status' => PayrollRun::STATUS_PENDING_APPROVAL,
                'submitted_at' => now(),
                'submitted_by_user_id' => $actorUserId,
            ])->save();

            return $run->fresh();
        });
    }
}
