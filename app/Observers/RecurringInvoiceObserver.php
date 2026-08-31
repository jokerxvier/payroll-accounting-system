<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pas\RecurringInvoice;

/**
 * Keeps a schedule's template lines auditable when the schedule is deleted.
 *
 * `pas_recurring_invoice_lines.recurring_invoice_id` is `cascadeOnDelete`, so
 * the database would remove the lines without Eloquent firing
 * `RecurringInvoiceLine::deleted`, and the AuditObserver would never see them.
 * The trail would show a schedule disappearing and nothing about what it had
 * been charging.
 *
 * Same trap as {@see InvoiceObserver} and {@see JournalEntryObserver}.
 *
 * The period claims are deliberately NOT swept: they cascade at the database
 * level and are a machine record rather than something a person authored.
 * Deleting the schedule is what releases them, which is the intended way to
 * start a schedule over.
 */
final class RecurringInvoiceObserver
{
    public function deleting(RecurringInvoice $schedule): void
    {
        foreach ($schedule->lines()->get() as $line) {
            $line->delete();
        }
    }
}
