<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\Invoice;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentAllocation;

/**
 * The single place an invoice's paid amount and payment status are derived.
 *
 * Everything that can change what an invoice has been paid — allocating a
 * payment, posting one, voiding one, deleting a draft — calls this instead of
 * adjusting `amount_paid_centavos` itself. An increment scattered across four
 * call sites drifts the first time one of them is missed, and a receivable
 * that quietly disagrees with its own allocations is the kind of error nobody
 * finds until a customer disputes a statement.
 *
 * **Only posted payments count.** A draft is a payment somebody has keyed but
 * not committed; letting one mark an invoice paid would show a document
 * settled on the strength of an entry that never reached the ledger. Voiding
 * works through the same filter, which is why a void needs to delete nothing:
 * the allocations stay as the record of what was applied and simply stop
 * being counted.
 */
final class InvoiceBalanceService
{
    /**
     * Recompute one invoice from its allocations and persist the result.
     */
    public function recompute(Invoice $invoice): Invoice
    {
        // A voided invoice is finished. Its ledger entry has been reversed,
        // and moving it back to `paid` because an old allocation still points
        // at it would resurrect a cancelled document.
        if ($invoice->isVoided()) {
            return $invoice;
        }

        $paid = $this->allocatedCentavosFor($invoice);

        $invoice->forceFill([
            'amount_paid_centavos' => $paid,
            'status' => $this->statusFor($invoice, $paid),
        ])->save();

        return $invoice;
    }

    /**
     * Recompute every invoice a payment touches.
     *
     * Takes the payment rather than a list of invoices so callers cannot
     * recompute half of them — a void that restored three balances out of
     * four would leave the fourth permanently overstated.
     */
    public function recomputeFor(Payment $payment): void
    {
        $invoiceIds = $payment->allocations()->pluck('invoice_id')->unique();

        Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->get()
            ->each(fn (Invoice $invoice) => $this->recompute($invoice));
    }

    /**
     * What has actually been paid against an invoice, counting posted
     * payments only.
     */
    public function allocatedCentavosFor(Invoice $invoice): int
    {
        return (int) PaymentAllocation::query()
            ->where('invoice_id', $invoice->getKey())
            ->whereHas('payment', fn ($query) => $query->where('status', Payment::STATUS_POSTED))
            ->sum('amount_centavos');
    }

    /**
     * What an invoice still owes, for allocation bounds.
     *
     * Derived from the allocations rather than read from
     * `amount_paid_centavos` so a caller mid-transaction — allocating while
     * the header has not been rewritten yet — still sees the truth.
     */
    public function remainingCentavosFor(Invoice $invoice): int
    {
        return $invoice->total_centavos - $this->allocatedCentavosFor($invoice);
    }

    /**
     * The status a given paid amount implies.
     *
     * `sent` is preserved when the document has been sent, so a future send
     * flow is not silently undone the first time someone pays. Nothing writes
     * `sent_at` today, so in practice this falls back to `approved`.
     */
    private function statusFor(Invoice $invoice, int $paidCentavos): string
    {
        if ($paidCentavos >= $invoice->total_centavos && $invoice->total_centavos > 0) {
            return Invoice::STATUS_PAID;
        }

        if ($paidCentavos > 0) {
            return Invoice::STATUS_PARTIALLY_PAID;
        }

        return $invoice->sent_at !== null
            ? Invoice::STATUS_SENT
            : Invoice::STATUS_APPROVED;
    }
}
