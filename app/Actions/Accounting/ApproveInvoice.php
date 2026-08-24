<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Exceptions\ClosedAccountingPeriodException;
use App\Exceptions\DocumentNumberUnavailableException;
use App\Models\Pas\DocumentNumberSeries;
use App\Models\Pas\Invoice;
use App\Services\Accounting\DocumentNumberAllocator;
use App\Services\Accounting\InvoicePostingService;
use App\Services\Accounting\InvoiceTotalsCalculator;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * `draft → approved`. The moment an invoice becomes a real document.
 *
 * Three things happen together or not at all:
 *
 *   1. The totals are recomputed from the lines, so an invoice cannot be
 *      issued carrying figures that no longer match what it says.
 *   2. A BIR-controlled serial is allocated.
 *   3. The document posts to the ledger.
 *
 * All three inside one transaction, which is the whole point. Payroll
 * posting deliberately swallows a ledger failure — staff still have to be
 * paid, and the books can be reconciled afterwards. An invoice is the
 * opposite: it is a numbered document handed to a third party, so issuing
 * one the books rejected would put a serial into the world with nothing
 * behind it. A posting failure here fails the approval, and the rollback
 * returns the serial rather than burning it — which is exactly what
 * {@see DocumentNumberAllocator} refuses to run outside a transaction for.
 */
final class ApproveInvoice
{
    public function __construct(
        private readonly InvoiceTotalsCalculator $calculator,
        private readonly DocumentNumberAllocator $numbers,
        private readonly InvoicePostingService $poster,
    ) {}

    /**
     * @throws DomainException Illegal status, or an invoice with no lines.
     * @throws DocumentNumberUnavailableException No series, inactive, or range exhausted.
     * @throws ClosedAccountingPeriodException The issue date falls in a closed period.
     */
    public function execute(Invoice $invoice, int $actorUserId): Invoice
    {
        if (! $invoice->isDraft()) {
            throw new DomainException(sprintf(
                'Cannot approve invoice %s from status [%s]. Only a draft can be approved.',
                $invoice->number ?? ('#'.$invoice->getKey()),
                $invoice->status,
            ));
        }

        return DB::transaction(function () use ($invoice, $actorUserId): Invoice {
            $lines = $invoice->lines()->with('taxRate')->get();

            if ($lines->isEmpty()) {
                throw new DomainException(sprintf(
                    'Invoice %s has no lines. Add at least one charge before approving it.',
                    $invoice->number ?? ('#'.$invoice->getKey()),
                ));
            }

            // Recompute rather than trust what was stored. A draft saved
            // before a tax rate changed would otherwise be issued showing
            // the old VAT, and the ledger would agree with a figure the
            // rates no longer support.
            $this->calculator->applyTo($invoice, $lines);

            foreach ($lines as $line) {
                $line->save();
            }

            if ($invoice->total_centavos <= 0) {
                throw new DomainException(sprintf(
                    'Invoice %s totals zero. An invoice must charge something.',
                    $invoice->number ?? ('#'.$invoice->getKey()),
                ));
            }

            $invoice->forceFill([
                'number' => $this->numbers->allocate($this->seriesTypeFor($invoice)),
                'status' => Invoice::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by_user_id' => $actorUserId,
            ])->save();

            // Inside the transaction on purpose — see the class docblock.
            $this->poster->post($invoice, $actorUserId);

            return $invoice->refresh();
        });
    }

    /**
     * Which numbering series this document draws from.
     *
     * A sales invoice takes a BIR-controlled serial. A purchase bill is
     * someone else's document, so the number we assign it is internal
     * reference only — it still comes from a series so bills are traceable,
     * but no Authority To Print applies to it.
     */
    private function seriesTypeFor(Invoice $invoice): string
    {
        return $invoice->isSales()
            ? DocumentNumberSeries::TYPE_SALES_INVOICE
            : DocumentNumberSeries::TYPE_BILL;
    }
}
