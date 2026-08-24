<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Exceptions\ClosedAccountingPeriodException;
use App\Models\Pas\Invoice;
use App\Models\Pas\JournalEntry;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an issued invoice.
 *
 * The document is not deleted and its number is not released. The Bureau
 * expects every serial in an authorised range to be accounted for, including
 * the cancelled ones — a missing number reads as a document that was issued
 * and hidden, which is the thing controlled numbering exists to prevent. A
 * voided invoice therefore stays on the list, keeps its serial, and prints
 * as cancelled.
 *
 * Its ledger effect is undone the only way posted books allow: by posting a
 * reversing entry through {@see ReverseJournalEntry}. Both the original entry
 * and the reversal stay posted and offset each other, so the receivable and
 * the output VAT come back to zero while the history of what happened stays
 * readable.
 *
 * A payment already allocated against the invoice blocks the void. Reversing
 * the sale while the money it collected remains applied to it would leave
 * the cash account backed by nothing — the receipt has to be reversed first.
 * (Payments arrive in Slice 7; the guard is here from the start so the rule
 * does not have to be retrofitted onto documents voided before it existed.)
 */
final class VoidInvoice
{
    public function __construct(
        private readonly ReverseJournalEntry $reverser,
    ) {}

    /**
     * @param  ?CarbonImmutable  $reversalDate  Date to post the reversal on.
     *                                          Defaults to the original entry's
     *                                          date, which the period guard
     *                                          rejects if that period has since
     *                                          closed.
     *
     * @throws DomainException Not issued, or has payments against it.
     * @throws ClosedAccountingPeriodException The reversal date falls in a closed period.
     */
    public function execute(
        Invoice $invoice,
        int $actorUserId,
        string $reason = '',
        ?CarbonImmutable $reversalDate = null,
    ): Invoice {
        if (! $invoice->isVoidable()) {
            throw new DomainException(sprintf(
                'Cannot void invoice %s from status [%s]. Only an issued document can be voided; a draft is deleted instead.',
                $invoice->number ?? ('#'.$invoice->getKey()),
                $invoice->status,
            ));
        }

        if ($invoice->amount_paid_centavos !== 0) {
            throw new DomainException(sprintf(
                'Invoice %s has %s already applied to it. Reverse the payment before voiding the invoice.',
                $invoice->number ?? ('#'.$invoice->getKey()),
                $invoice->amountPaid()->toDecimalString(),
            ));
        }

        return DB::transaction(function () use ($invoice, $actorUserId, $reason, $reversalDate): Invoice {
            $entry = $invoice->journal_entry_id !== null
                ? JournalEntry::query()->find($invoice->journal_entry_id)
                : null;

            // An entry that is already reversed needs no second reversal —
            // overshooting would leave the accounts wrong by the full amount
            // in the other direction.
            if ($entry !== null && $entry->isReversible()) {
                $this->reverser->execute(
                    $entry,
                    $actorUserId,
                    $reversalDate,
                    $reason !== '' ? $reason : sprintf(
                        'Void of invoice %s',
                        $invoice->number ?? ('#'.$invoice->getKey()),
                    ),
                );
            }

            $invoice->forceFill([
                'status' => Invoice::STATUS_VOIDED,
                'voided_at' => now(),
                'voided_by_user_id' => $actorUserId,
                'void_reason' => $reason !== '' ? $reason : null,
            ])->save();

            return $invoice->refresh();
        });
    }
}
