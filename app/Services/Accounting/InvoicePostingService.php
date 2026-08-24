<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Actions\Accounting\ApproveInvoice;
use App\Actions\Accounting\PostJournalEntry;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts an invoice to the general ledger.
 *
 * A sales invoice:
 *
 *   DR  Accounts Receivable          total    ← contact override, else AR_CONTROL
 *     CR  income account (per line)  net      ← the line's own account
 *     CR  Output VAT                 vat      ← VAT_OUTPUT system account
 *
 * A purchase bill is the mirror image: the net debits an expense account per
 * line, input VAT debits VAT_INPUT, and the total credits Accounts Payable.
 *
 * The entry balances by construction, since
 * `total = vatable + exempt + zero_rated + vat`. It is asserted anyway.
 * {@see PostJournalEntry} would reject an unbalanced entry with a generic
 * message about debits and credits; catching it here says the real thing,
 * which is that the stored header disagrees with its own line items.
 *
 * Line nets are aggregated per account, not emitted one per invoice line.
 * Ten lines of tuition against one income account are one ledger line — the
 * invoice lines remain the itemised record, and the ledger records the
 * accounting effect.
 *
 * Idempotent on `journal_entry_id`, as payroll posting is. Unlike payroll,
 * a caller must let a failure here propagate: {@see ApproveInvoice}
 * posts inside the approval transaction so an invoice cannot be issued to a
 * third party while the books reject it.
 */
final class InvoicePostingService
{
    public function __construct(
        private readonly PostJournalEntry $poster,
    ) {}

    /**
     * @throws DomainException Invoice is not approvable, has no lines, or its totals disagree.
     * @throws RuntimeException A required system account is missing from the chart.
     */
    public function post(Invoice $invoice, int $actorUserId): JournalEntry
    {
        if ($invoice->journal_entry_id !== null) {
            $existing = JournalEntry::query()->find($invoice->journal_entry_id);

            if ($existing !== null) {
                return $existing;
            }
        }

        /** @var Collection<int, InvoiceLine> $lines */
        $lines = $invoice->lines()->with('taxRate')->get();

        if ($lines->isEmpty()) {
            throw new DomainException(sprintf(
                'Invoice %s has no lines, so there is nothing to post.',
                $this->describe($invoice),
            ));
        }

        if (! $invoice->totalsAreConsistent()) {
            throw new DomainException(sprintf(
                'Invoice %s has a total of %d centavos that does not equal its own sales buckets plus VAT (%d). Recalculate it before posting.',
                $this->describe($invoice),
                $invoice->total_centavos,
                $invoice->vatable_sales_centavos
                    + $invoice->vat_exempt_sales_centavos
                    + $invoice->zero_rated_sales_centavos
                    + $invoice->vat_centavos,
            ));
        }

        if ($invoice->total_centavos <= 0) {
            throw new DomainException(sprintf(
                'Invoice %s totals zero, so it moves nothing and cannot be posted.',
                $this->describe($invoice),
            ));
        }

        $isSales = $invoice->isSales();
        $netByAccount = $this->aggregateNetsByAccount($lines);

        return DB::transaction(function () use (
            $invoice,
            $actorUserId,
            $isSales,
            $netByAccount,
        ): JournalEntry {
            $entry = JournalEntry::create([
                'date' => $invoice->issue_date,
                'reference' => $invoice->number ?? sprintf('INV-%d', $invoice->getKey()),
                'narration' => $this->narration($invoice),
                'status' => JournalEntry::STATUS_DRAFT,
                // The forward trace: a posted figure walks back to the
                // document that caused it.
                'source_type' => Invoice::class,
                'source_id' => $invoice->getKey(),
            ]);

            $lineNumber = 1;
            $description = $this->narration($invoice);

            // The control account carries the gross — what the counterparty
            // owes us, or what we owe them.
            JournalEntryLine::create([
                'journal_entry_id' => $entry->getKey(),
                'line_number' => $lineNumber++,
                'account_id' => $this->controlAccountFor($invoice)->getKey(),
                'debit_centavos' => $isSales ? $invoice->total_centavos : 0,
                'credit_centavos' => $isSales ? 0 : $invoice->total_centavos,
                'description' => $description,
            ]);

            foreach ($netByAccount as $accountId => $netCentavos) {
                if ($netCentavos === 0) {
                    // A discount line exactly cancelling a charge against the
                    // same account. A zero line would be noise, and
                    // PostJournalEntry rejects lines that move nothing.
                    continue;
                }

                // Sign decides the side, so a net-negative account (all
                // discount, no charge) flips rather than becoming a negative
                // debit — which the posting action refuses outright.
                $isCredit = $isSales ? $netCentavos > 0 : $netCentavos < 0;
                $magnitude = abs($netCentavos);

                JournalEntryLine::create([
                    'journal_entry_id' => $entry->getKey(),
                    'line_number' => $lineNumber++,
                    'account_id' => (int) $accountId,
                    'debit_centavos' => $isCredit ? 0 : $magnitude,
                    'credit_centavos' => $isCredit ? $magnitude : 0,
                    'description' => $description,
                ]);
            }

            if ($invoice->vat_centavos !== 0) {
                // Output VAT on a sale is a liability owed to the BIR;
                // input VAT on a purchase is an asset creditable against it.
                $vatAccount = $this->systemAccount(
                    $isSales
                        ? ChartOfAccount::SYSTEM_VAT_OUTPUT
                        : ChartOfAccount::SYSTEM_VAT_INPUT,
                );

                JournalEntryLine::create([
                    'journal_entry_id' => $entry->getKey(),
                    'line_number' => $lineNumber++,
                    'account_id' => $vatAccount->getKey(),
                    'debit_centavos' => $isSales ? 0 : $invoice->vat_centavos,
                    'credit_centavos' => $isSales ? $invoice->vat_centavos : 0,
                    'description' => $description,
                ]);
            }

            $posted = $this->poster->execute($entry->fresh(), $actorUserId);

            $invoice->forceFill(['journal_entry_id' => $posted->getKey()])->save();

            return $posted;
        });
    }

    public function hasPosted(Invoice $invoice): bool
    {
        return $invoice->journal_entry_id !== null;
    }

    /**
     * Fold line nets into per-account totals.
     *
     * Tax is deliberately excluded — it does not belong to the income or
     * expense account, it belongs to the VAT control account, and it is
     * taken from the invoice header so the ledger agrees with the printed
     * face rather than with a re-summation of the lines.
     *
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array<int, int>
     */
    private function aggregateNetsByAccount(Collection $lines): array
    {
        /** @var array<int, int> $buckets */
        $buckets = [];

        foreach ($lines as $line) {
            $accountId = (int) $line->account_id;
            $buckets[$accountId] = ($buckets[$accountId] ?? 0) + $line->line_net_centavos;
        }

        return $buckets;
    }

    /**
     * The receivable or payable account this document posts against.
     *
     * The contact's own override wins, so a school that tracks a major
     * supplier or a scholarship fund on its own control account gets that.
     * Otherwise the school's AR_CONTROL / AP_CONTROL — the fallback the
     * contact register was built around.
     */
    private function controlAccountFor(Invoice $invoice): ChartOfAccount
    {
        $contact = $invoice->contact;

        $overrideId = $invoice->isSales()
            ? $contact?->receivable_account_id
            : $contact?->payable_account_id;

        if ($overrideId !== null) {
            $override = ChartOfAccount::query()->find($overrideId);

            if ($override !== null) {
                return $override;
            }
        }

        return $this->systemAccount(
            $invoice->isSales()
                ? ChartOfAccount::SYSTEM_AR_CONTROL
                : ChartOfAccount::SYSTEM_AP_CONTROL,
        );
    }

    /**
     * Resolve a system account within the posting school.
     *
     * Throws rather than inventing one. A missing control account is a setup
     * error the operator has to fix, and quietly posting real money into a
     * substitute would put it somewhere nobody chose.
     */
    private function systemAccount(string $systemCode): ChartOfAccount
    {
        $account = ChartOfAccount::query()->where('system_code', $systemCode)->first();

        if ($account === null) {
            throw new RuntimeException(sprintf(
                "This school's chart of accounts has no '%s' account, which invoicing needs in order to post.",
                $systemCode,
            ));
        }

        return $account;
    }

    private function narration(Invoice $invoice): string
    {
        return sprintf(
            '%s %s — %s',
            $invoice->isSales() ? 'Sales invoice' : 'Purchase bill',
            $invoice->number ?? ('#'.$invoice->getKey()),
            $invoice->contact?->name ?? 'Unknown contact',
        );
    }

    private function describe(Invoice $invoice): string
    {
        return $invoice->number ?? ('#'.$invoice->getKey());
    }
}
