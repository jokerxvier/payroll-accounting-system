<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\Pas\Invoice;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentAllocation;
use App\Services\Accounting\InvoiceBalanceService;
use App\ValueObjects\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a draft payment's allocations, enforcing every rule about what a
 * payment may be applied to.
 *
 * Five invariants, all checked inside the writing transaction so a rejected
 * set leaves nothing behind:
 *
 *   1. Every allocation is positive, and their sum does not exceed the
 *      payment. Anything left over is the advance, which is legitimate.
 *   2. No allocation exceeds what that invoice still owes — an invoice can
 *      never be over-paid, including across several separate payments.
 *   3. The invoice has been issued. A draft carries no serial and has not
 *      reached the ledger; a voided one has been reversed out of it.
 *   4. The document type agrees: a receipt settles sales invoices, a
 *      disbursement settles bills.
 *   5. The contact agrees.
 *
 * Rules 3 to 5 are enforced here rather than left to the database because
 * none of them is a broken reference. The row exists — it is simply the wrong
 * one, which a foreign key cannot tell you.
 */
final class ApplyPaymentAllocations
{
    public function __construct(
        private readonly InvoiceBalanceService $balances,
    ) {}

    /**
     * @param  array<int, array{invoice_id: int|string, amount_centavos: int|string}>  $allocations
     *
     * @throws DomainException Any invariant above.
     */
    public function execute(Payment $payment, array $allocations): Payment
    {
        if (! $payment->isMutable()) {
            throw new DomainException(sprintf(
                'Cannot change the allocations on payment #%d from status [%s]. Only a draft can be edited.',
                $payment->getKey(),
                $payment->status,
            ));
        }

        return DB::transaction(function () use ($payment, $allocations): Payment {
            // Clear first so the remaining-balance check below measures what
            // the invoice owes without this payment's own previous
            // allocations counting against it. Deletion goes through Eloquent
            // so each removed row still writes an audit entry.
            $touchedInvoiceIds = $payment->allocations()->pluck('invoice_id')->all();

            foreach ($payment->allocations()->get() as $existing) {
                $existing->delete();
            }

            $merged = $this->merge($allocations);
            $total = 0;

            foreach ($merged as $invoiceId => $amountCentavos) {
                if ($amountCentavos <= 0) {
                    throw new DomainException(
                        'An allocation must be a positive amount. Remove the line instead of allocating zero.',
                    );
                }

                $invoice = $this->resolveInvoice($payment, (int) $invoiceId);
                $remaining = $this->balances->remainingCentavosFor($invoice);

                if ($amountCentavos > $remaining) {
                    throw new DomainException(sprintf(
                        '%s only has %s outstanding, so %s cannot be applied to it.',
                        $invoice->number ?? ('Draft #'.$invoice->getKey()),
                        Money::fromCentavos($remaining)->toDecimalString(),
                        Money::fromCentavos($amountCentavos)->toDecimalString(),
                    ));
                }

                PaymentAllocation::create([
                    'payment_id' => $payment->getKey(),
                    'invoice_id' => $invoice->getKey(),
                    'amount_centavos' => $amountCentavos,
                ]);

                $total += $amountCentavos;
                $touchedInvoiceIds[] = $invoice->getKey();
            }

            if ($total > $payment->amount_centavos) {
                throw new DomainException(sprintf(
                    'This payment is %s but %s has been allocated. Reduce the allocations, or raise the payment amount.',
                    $payment->amount()->toDecimalString(),
                    Money::fromCentavos($total)->toDecimalString(),
                ));
            }

            $payment->forceFill(['allocated_centavos' => $total])->save();

            // Includes invoices this payment used to touch and no longer
            // does, so a de-allocated document has its balance restored.
            Invoice::query()
                ->whereIn('id', array_unique($touchedInvoiceIds))
                ->get()
                ->each(fn (Invoice $invoice) => $this->balances->recompute($invoice));

            return $payment->refresh();
        });
    }

    /**
     * Fold duplicate rows for the same invoice into one.
     *
     * The unique on `(payment_id, invoice_id)` would reject the second row
     * anyway, but with a constraint-violation message that says nothing about
     * what the operator did. Two lines for one invoice is plainly a request
     * to pay the sum of them.
     *
     * @param  array<int, array{invoice_id: int|string, amount_centavos: int|string}>  $allocations
     * @return array<int, int>
     */
    private function merge(array $allocations): array
    {
        /** @var array<int, int> $merged */
        $merged = [];

        foreach ($allocations as $allocation) {
            $invoiceId = (int) $allocation['invoice_id'];
            $merged[$invoiceId] = ($merged[$invoiceId] ?? 0) + (int) $allocation['amount_centavos'];
        }

        return $merged;
    }

    /**
     * @throws DomainException Missing, unissued, wrong type, or wrong contact.
     */
    private function resolveInvoice(Payment $payment, int $invoiceId): Invoice
    {
        $invoice = Invoice::query()->find($invoiceId);

        if ($invoice === null) {
            throw new DomainException(
                sprintf('Invoice #%d does not exist in this school.', $invoiceId),
            );
        }

        if (! $invoice->isIssued()) {
            throw new DomainException(sprintf(
                '%s is %s, so nothing can be applied to it. Only an issued document can be paid.',
                $invoice->number ?? ('Draft #'.$invoice->getKey()),
                $invoice->status,
            ));
        }

        if ($invoice->type !== $payment->settlesInvoiceType()) {
            throw new DomainException(sprintf(
                'A %s settles %s documents, and %s is a %s.',
                $payment->isReceipt() ? 'receipt' : 'disbursement',
                $payment->settlesInvoiceType(),
                $invoice->number ?? ('#'.$invoice->getKey()),
                $invoice->type,
            ));
        }

        if ($invoice->contact_id !== $payment->contact_id) {
            throw new DomainException(sprintf(
                '%s belongs to a different contact than this payment. Money from one counterparty cannot settle another\'s account.',
                $invoice->number ?? ('#'.$invoice->getKey()),
            ));
        }

        return $invoice;
    }
}
