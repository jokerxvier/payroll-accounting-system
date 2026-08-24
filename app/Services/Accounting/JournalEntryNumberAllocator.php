<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Allocates journal entry numbers, `JE-{year}-{00001}`, sequential per
 * school per year.
 *
 * Journal entries are internal records, not BIR-controlled documents, so
 * they do not need the `pas_document_number_series` machinery Slice 5 brings
 * for invoices and receipts — but they do still need to be unique and
 * readable, and `pas_journal_entries` carries a `(school_id, entry_number)`
 * unique that a race would otherwise trip.
 *
 * The allocation therefore happens inside the caller's transaction with the
 * candidate rows locked. MySQL takes real row locks; sqlite (the test
 * connection) ignores `lockForUpdate` but runs single-threaded, so the
 * behaviour under test still matches.
 *
 * Numbers may gap if a transaction rolls back after allocating. That is
 * acceptable here in a way it will not be for BIR invoice series: a gap in
 * an internal journal sequence is untidy, whereas a gap in a controlled
 * receipt series is an audit finding.
 */
final class JournalEntryNumberAllocator
{
    /**
     * Next entry number for the year `$date` falls in.
     *
     * Call inside a transaction — the lock is only meaningful there.
     */
    public function allocate(CarbonImmutable $date): string
    {
        $year = $date->format('Y');
        $prefix = "JE-{$year}-";

        $latest = JournalEntry::query()
            ->where('entry_number', 'like', $prefix.'%')
            ->orderByDesc('entry_number')
            ->lockForUpdate()
            ->value('entry_number');

        $next = $latest === null
            ? 1
            : ((int) substr($latest, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Reserve the next number using the given connection transaction, when
     * the caller has no transaction of its own to lend.
     */
    public function allocateInOwnTransaction(CarbonImmutable $date): string
    {
        return DB::transaction(fn (): string => $this->allocate($date));
    }
}
