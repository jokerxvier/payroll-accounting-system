<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\PayrollRun;
use App\Services\Accounting\LedgerPostingService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `approved → posted`. Final state. Stamps `posted_at` + the actor.
 *
 * Posted runs are absolutely immutable — even voiding is denied. This is
 * the cliff after which payslips are considered released to the books.
 *
 * Phase 5 Slice 3 made that literal: posting now also writes the run to the
 * general ledger through {@see LedgerPostingService}, which is the seam
 * `rules/PLAN.md` §11 promised v1 would leave behind.
 *
 * The ledger posting is deliberately NOT allowed to fail the payroll post.
 * Payroll and the books have different owners and different calendars — an
 * accountant may have closed the period, or the chart may be missing a
 * mapped account — and neither is a reason to block paying people. The run
 * posts, the ledger attempt is logged, and `journal_entry_id` stays null so
 * the run can be retried against the ledger once the books are ready.
 * {@see LedgerPostingService::post()} is idempotent precisely so that retry
 * is safe.
 */
final class PostPayrollRunAction
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
    ) {}

    public function execute(PayrollRun $run, int $actorUserId): PayrollRun
    {
        // DEMO: approval is bypassed, so `computed` runs post directly
        // alongside the usual `approved`. Drop STATUS_COMPUTED to restore
        // the maker-checker flow (mirror the change in PayrollRun::isPostable).
        if (! in_array($run->status, [PayrollRun::STATUS_COMPUTED, PayrollRun::STATUS_APPROVED], true)) {
            throw new DomainException(sprintf(
                'Cannot post a payroll run from status [%s]. Expected [computed] or [approved].',
                $run->status,
            ));
        }

        $posted = DB::transaction(function () use ($run, $actorUserId): PayrollRun {
            $run->forceFill([
                'status' => PayrollRun::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_user_id' => $actorUserId,
            ])->save();

            return $run->fresh();
        });

        $this->postToLedger($posted, $actorUserId);

        return $posted->fresh();
    }

    /**
     * Write the run to the general ledger, outside the status transaction.
     *
     * Outside on purpose: rolling the payroll post back because the books
     * are closed would be the wrong trade. The failure is logged with the
     * run id so it can be found and retried, and it is swallowed rather
     * than surfaced because the payroll post itself genuinely succeeded —
     * the caller's flash message should not claim otherwise.
     */
    private function postToLedger(PayrollRun $run, int $actorUserId): void
    {
        try {
            $this->ledger->post($run, $actorUserId);
        } catch (Throwable $e) {
            Log::warning('Payroll run posted but could not reach the ledger.', [
                'payroll_run_id' => $run->id,
                'reason' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }
}
