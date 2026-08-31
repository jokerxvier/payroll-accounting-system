<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Allocates invoice and bill numbers, `INV-{year}-{00001}` and
 * `BILL-{year}-{00001}`, sequential per school per type per year.
 *
 * This replaces the `pas_document_number_series` machinery removed on
 * 2026-08-30, and is deliberately a much smaller thing. There is no series
 * row to configure, no Authority To Print, no authorised range, and nothing
 * an operator has to set up before the first invoice — the number is derived
 * from what has already been issued, the same way
 * {@see JournalEntryNumberAllocator} derives a journal reference.
 *
 * **Gaps are tolerated.** An abandoned draft keeps its number, and a rolled
 * back transaction leaves a hole. That was unacceptable while these were
 * BIR-controlled serials, where a gap in an authorised range is an audit
 * finding; it is merely untidy now that they are internal references. If BIR
 * numbering is ever reinstated, this class is not the thing to extend — the
 * gapless guarantee has to be structural, which is what the old allocator's
 * refusal to run outside a transaction was for.
 *
 * Uniqueness still matters: `pas_invoices` carries a
 * `(school_id, type, number)` unique that a race would trip, so the read is
 * taken with the candidate rows locked. MySQL takes real row locks; sqlite
 * (the test connection) ignores the hint but runs single-threaded, so
 * behaviour under test matches.
 *
 * Numbering is per YEAR OF ISSUE DATE, not of allocation. An invoice
 * backdated into last year draws from last year's block, which keeps a
 * document's number consistent with the period it belongs to — the same rule
 * the ledger reports apply to entry dates.
 */
final class InvoiceNumberAllocator
{
    private const PREFIXES = [
        Invoice::TYPE_SALES => 'INV',
        Invoice::TYPE_PURCHASE => 'BILL',
    ];

    /**
     * Next number for this document type and year.
     *
     * Call inside a transaction — the lock is only meaningful there.
     */
    public function allocate(string $type, CarbonImmutable $issueDate): string
    {
        $prefix = sprintf(
            '%s-%s-',
            self::PREFIXES[$type] ?? 'DOC',
            $issueDate->format('Y'),
        );

        $latest = Invoice::query()
            ->where('type', $type)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->lockForUpdate()
            ->value('number');

        $next = $latest === null
            ? 1
            : ((int) substr($latest, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Reserve the next number when the caller has no transaction to lend.
     */
    public function allocateInOwnTransaction(string $type, CarbonImmutable $issueDate): string
    {
        return DB::transaction(fn (): string => $this->allocate($type, $issueDate));
    }
}
