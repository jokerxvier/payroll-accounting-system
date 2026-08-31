<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\RecurringInvoice;
use App\Models\Pas\RecurringInvoiceLine;

/**
 * Writes a schedule's template lines.
 *
 * Lifted out of `RecurringInvoiceController` for the same reason
 * {@see InvoiceLineWriter} was lifted out of `InvoiceController`: a second
 * caller arrived. A schedule can now be started from an invoice, so the
 * controller's `update()` and `StartInvoiceSchedule` both need to write these
 * rows, and two copies of the loop would drift on the one thing that must not
 * drift — the quantity normalisation.
 *
 * No totals here, unlike the invoice writer. A schedule stores no net or tax:
 * what it will charge depends on the tax rates in force on the day it fires,
 * so a figure computed now would be a guess stored as a fact.
 *
 * The caller owns the transaction.
 */
final class RecurringInvoiceLineWriter
{
    /**
     * Replace a schedule's template lines wholesale.
     *
     * Deleted through Eloquent so each removed line writes its own audit row —
     * `recurring_invoice_id` is `cascadeOnDelete`, and the FK cascade alone
     * would take them without ever firing `deleted`.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function replace(RecurringInvoice $schedule, array $lines): void
    {
        foreach ($schedule->lines()->get() as $existing) {
            $existing->delete();
        }

        foreach (array_values($lines) as $index => $line) {
            RecurringInvoiceLine::create([
                'recurring_invoice_id' => $schedule->getKey(),
                'line_number' => $index + 1,
                'description' => (string) $line['description'],
                // A decimal string to four places, never a float: the column
                // is decimal(12,4) and the model deliberately leaves it uncast.
                'quantity' => number_format((float) $line['quantity'], 4, '.', ''),
                'unit_price_centavos' => (int) $line['unit_price_centavos'],
                'account_id' => (int) $line['account_id'],
                'tax_rate_id' => isset($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
            ]);
        }
    }
}
