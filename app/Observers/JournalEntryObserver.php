<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pas\JournalEntry;

/**
 * Keeps a journal entry's lines auditable when the entry is deleted.
 *
 * `pas_journal_entry_lines.journal_entry_id` is `cascadeOnDelete`, so the
 * database would remove the lines without Eloquent ever firing
 * `JournalEntryLine::deleted` — and the AuditObserver would never see them.
 * The same trap let payroll-run deletes destroy payslips silently.
 *
 * Only drafts are ever deletable (JournalEntryPolicy refuses the rest, and
 * posted entries are voided by reversal instead), so the volume here is a
 * handful of rows rather than the thousands a payroll run can carry — no
 * chunking needed.
 */
final class JournalEntryObserver
{
    public function deleting(JournalEntry $entry): void
    {
        foreach ($entry->lines()->get() as $line) {
            $line->delete();
        }
    }
}
