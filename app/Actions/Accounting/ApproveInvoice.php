<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Exceptions\ClosedAccountingPeriodException;
use App\Models\Pas\Invoice;
use App\Services\Accounting\InvoiceNumberAllocator;
use App\Services\Accounting\InvoicePostingService;
use App\Services\Accounting\InvoiceTotalsCalculator;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * `draft → approved`. The moment an invoice becomes a real document.
 *
 * Two things happen together or not at all:
 *
 *   1. The totals are recomputed from the lines, so an invoice cannot be
 *      issued carrying figures that no longer match what it says.
 *   2. The document posts to the ledger.
 *
 * Both inside one transaction. Payroll posting deliberately swallows a
 * ledger failure — staff still have to be paid, and the books can be
 * reconciled afterwards. An invoice is the opposite: it is a document handed
 * to a third party, so issuing one the books rejected would put a claim into
 * the world with nothing behind it. A posting failure here fails the
 * approval.
 *
 * Numbering is deliberately NOT a step here. The BIR-controlled serial that
 * used to be allocated at approval was removed on 2026-08-30; what replaced
 * it, {@see InvoiceNumberAllocator}, runs at DRAFT
 * CREATION instead, from `CreateInvoiceDraft`. Gaps are tolerated there — an
 * abandoned draft keeps its number — which is fine for an internal reference
 * and would not be for a controlled serial. Reinstating BIR numbering means a
 * structurally gapless allocator, not moving this one back here.
 *
 * One document type does not pass through at all: an opening item carried in
 * from a school's previous books is created already issued by
 * {@see RecordOpeningItems}, keeping the number it was actually issued under
 * and never posting. This action refuses it on the `isDraft()` guard below,
 * which is what stops the cutover receivable being counted twice.
 */
final class ApproveInvoice
{
    public function __construct(
        private readonly InvoiceTotalsCalculator $calculator,
        private readonly InvoicePostingService $poster,
    ) {}

    /**
     * @throws DomainException Illegal status, or an invoice with no lines.
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
                'status' => Invoice::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by_user_id' => $actorUserId,
            ])->save();

            // Inside the transaction on purpose — see the class docblock.
            $this->poster->post($invoice, $actorUserId);

            return $invoice->refresh();
        });
    }
}
