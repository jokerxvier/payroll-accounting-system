<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;

/**
 * Writes a draft's lines and recomputes its totals.
 *
 * Lifted out of `InvoiceController` so a recurring schedule can build an
 * invoice the same way a person does. While the form was the only door, a
 * private controller method was the right home for this; a second caller makes
 * it a place where two implementations would quietly drift apart, and the
 * thing they would drift on is money.
 *
 * The caller owns the transaction.
 */
final class InvoiceLineWriter
{
    public function __construct(
        private readonly InvoiceTotalsCalculator $calculator,
    ) {}

    /**
     * Replace a draft's lines wholesale, then recompute the totals.
     *
     * Deleting and re-inserting rather than diffing, for the same reason the
     * journal does: a draft's lines have no identity worth preserving, and
     * the audit trail reads better as "these lines were replaced" than as a
     * scatter of per-line edits. Deletion goes through Eloquent so each
     * removed line still writes its own audit row.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function replace(Invoice $invoice, array $lines): void
    {
        foreach ($invoice->lines()->get() as $existing) {
            $existing->delete();
        }

        $created = [];

        foreach (array_values($lines) as $index => $line) {
            $created[] = InvoiceLine::create([
                'invoice_id' => $invoice->getKey(),
                'line_number' => $index + 1,
                'description' => (string) $line['description'],
                'quantity' => number_format((float) $line['quantity'], 4, '.', ''),
                'unit_price_centavos' => (int) $line['unit_price_centavos'],
                'account_id' => (int) $line['account_id'],
                'tax_rate_id' => isset($line['tax_rate_id'])
                    ? (int) $line['tax_rate_id']
                    : null,
            ]);
        }

        // Load the rates the calculator needs in one query rather than one
        // per line.
        $models = InvoiceLine::query()
            ->with('taxRate')
            ->whereIn('id', array_map(static fn (InvoiceLine $l): int => $l->getKey(), $created))
            ->orderBy('line_number')
            ->get();

        $this->calculator->applyTo($invoice, $models);

        foreach ($models as $model) {
            $model->save();
        }

        $invoice->save();
    }
}
