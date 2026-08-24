<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Exceptions\ClosedAccountingPeriodException;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\Payment;
use App\Services\Accounting\InvoiceBalanceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a posted payment.
 *
 * The ledger effect is reversed the only way posted books allow — by posting
 * a mirror entry through {@see ReverseJournalEntry}, with both entries
 * staying posted so they offset. The payment itself is kept and marked
 * voided; deleting it would erase the fact that money was recorded and then
 * corrected.
 *
 * **The allocations are deliberately left alone.** They are the record of
 * what was applied to what, and {@see InvoiceBalanceService} counts only
 * allocations belonging to posted payments — so the moment the status flips,
 * every invoice this payment settled goes back to owing what it owed, without
 * a single row being destroyed.
 *
 * The flip therefore has to happen before the recomputation, exactly as
 * {@see PostPayment} orders it the other way round.
 */
final class VoidPayment
{
    public function __construct(
        private readonly ReverseJournalEntry $reverser,
        private readonly InvoiceBalanceService $balances,
    ) {}

    /**
     * @param  ?CarbonImmutable  $reversalDate  Date to post the reversal on.
     *                                          Defaults to the original entry's
     *                                          date, which the period guard
     *                                          rejects if that period has closed.
     *
     * @throws DomainException Not posted.
     * @throws ClosedAccountingPeriodException The reversal date falls in a closed period.
     */
    public function execute(
        Payment $payment,
        int $actorUserId,
        string $reason = '',
        ?CarbonImmutable $reversalDate = null,
    ): Payment {
        if (! $payment->isVoidable()) {
            throw new DomainException(sprintf(
                'Cannot void payment #%d from status [%s]. Only a posted payment can be voided; a draft is deleted instead.',
                $payment->getKey(),
                $payment->status,
            ));
        }

        return DB::transaction(function () use ($payment, $actorUserId, $reason, $reversalDate): Payment {
            $entry = $payment->journal_entry_id !== null
                ? JournalEntry::query()->find($payment->journal_entry_id)
                : null;

            // An entry already reversed needs no second reversal —
            // overshooting would leave the accounts wrong by the full amount
            // in the other direction.
            if ($entry !== null && $entry->isReversible()) {
                $this->reverser->execute(
                    $entry,
                    $actorUserId,
                    $reversalDate,
                    $reason !== '' ? $reason : sprintf('Void of payment #%d', $payment->getKey()),
                );
            }

            $payment->forceFill([
                'status' => Payment::STATUS_VOIDED,
                'voided_at' => now(),
                'voided_by_user_id' => $actorUserId,
                'void_reason' => $reason !== '' ? $reason : null,
            ])->save();

            // After the flip — the allocations stop counting on their own.
            $this->balances->recomputeFor($payment);

            return $payment->refresh();
        });
    }
}
