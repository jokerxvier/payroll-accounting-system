<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\PayrollRun;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * `pending_approval → approved`. Stamps `approved_at` + the actor.
 *
 * Once approved, the run is locked (`PayrollRun::isLocked()`); voiding is
 * still possible but re-computation isn't. Posting is the only forward
 * transition from here.
 */
final class ApprovePayrollRunAction
{
    public function execute(PayrollRun $run, int $actorUserId): PayrollRun
    {
        if ($run->status !== PayrollRun::STATUS_PENDING_APPROVAL) {
            throw new DomainException(sprintf(
                'Cannot approve a payroll run from status [%s]. Expected [pending_approval].',
                $run->status,
            ));
        }

        return DB::transaction(function () use ($run, $actorUserId): PayrollRun {
            $run->forceFill([
                'status' => PayrollRun::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by_user_id' => $actorUserId,
            ])->save();

            return $run->fresh();
        });
    }
}
