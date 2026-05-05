<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\PayrollRun;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Voids a payroll run from any non-terminal state. Stamps `voided_at` +
 * the actor and switches `status` to `voided`. The payslip rows stay
 * visible — the project's "void don't delete" convention preserves audit
 * history; downstream reports filter on status to exclude voided runs.
 *
 * Voiding is denied on `posted` runs — those are the absolute final state.
 * It's also denied on already-voided runs (no-op safety).
 */
final class VoidPayrollRunAction
{
    public function execute(PayrollRun $run, int $actorUserId): PayrollRun
    {
        if ($run->status === PayrollRun::STATUS_POSTED) {
            throw new DomainException(
                'Cannot void a payroll run that has already been posted. Posted runs are final.',
            );
        }

        if ($run->status === PayrollRun::STATUS_VOIDED) {
            throw new DomainException('Run is already voided.');
        }

        return DB::transaction(function () use ($run, $actorUserId): PayrollRun {
            $run->forceFill([
                'status' => PayrollRun::STATUS_VOIDED,
                'voided_at' => now(),
                'voided_by_user_id' => $actorUserId,
            ])->save();

            return $run->fresh();
        });
    }
}
