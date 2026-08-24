<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pas\Invoice;

/**
 * Keeps an invoice's lines auditable when the invoice is deleted.
 *
 * `pas_invoice_lines.invoice_id` is `cascadeOnDelete`, so the database would
 * remove the lines without Eloquent firing `InvoiceLine::deleted` and the
 * AuditObserver would never see them. The audit trail would then show the
 * invoice disappearing and nothing at all about what was on it.
 *
 * Only drafts are ever deletable — InvoicePolicy refuses the rest, and an
 * issued invoice is cancelled by voiding so its serial stays accounted for —
 * so this is a handful of rows, not a chunked delete.
 *
 * Same trap as {@see JournalEntryObserver}, and as the payroll-run delete
 * that used to destroy payslips silently.
 */
final class InvoiceObserver
{
    public function deleting(Invoice $invoice): void
    {
        foreach ($invoice->lines()->get() as $line) {
            $line->delete();
        }
    }
}
