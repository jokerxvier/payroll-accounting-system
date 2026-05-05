<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\PayrollRun;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * `approved → posted`. Final state. Stamps `posted_at` + the actor.
 *
 * Posted runs are absolutely immutable — even voiding is denied. This is
 * the cliff after which payslips are considered "released to the books"
 * (general-ledger integration is out-of-scope per `rules/PLAN.md` v1
 * non-goals; for v1 it's a status flag without a downstream side effect).
 */
final class PostPayrollRunAction
{
    public function execute(PayrollRun $run, int $actorUserId): PayrollRun
    {
        if ($run->status !== PayrollRun::STATUS_APPROVED) {
            throw new DomainException(sprintf(
                'Cannot post a payroll run from status [%s]. Expected [approved].',
                $run->status,
            ));
        }

        return DB::transaction(function () use ($run, $actorUserId): PayrollRun {
            $run->forceFill([
                'status' => PayrollRun::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => $actorUserId,
            ])->save();

            return $run->fresh();
        });
    }
}
