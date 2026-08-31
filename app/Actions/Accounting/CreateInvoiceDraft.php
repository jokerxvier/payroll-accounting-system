<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Models\Pas\Invoice;
use App\Services\Accounting\InvoiceHeaderAttributes;
use App\Services\Accounting\InvoiceLineWriter;
use App\Services\Accounting\InvoiceNumberAllocator;
use Illuminate\Support\Facades\DB;

/**
 * `nothing → draft`. The one way an invoice comes into existence.
 *
 * There was no such door until a recurring schedule needed one: drafting lived
 * in two private methods on `InvoiceController`, reachable only through a
 * request. A generator that reimplemented them would be a second way to build
 * an invoice, and the two would drift on the details that matter — how a
 * quantity is normalised, when a number is allocated, whether the student name
 * is snapshotted.
 *
 * A draft deliberately does NOT reach the ledger. It carries a number and
 * totals and nothing else; `ApproveInvoice` is what makes it a real document.
 * That is what makes it safe for an unattended job to create one.
 */
final class CreateInvoiceDraft
{
    public function __construct(
        private readonly InvoiceNumberAllocator $numbers,
        private readonly InvoiceLineWriter $lines,
        private readonly InvoiceHeaderAttributes $header,
    ) {}

    /**
     * @param  array<string, mixed>  $header  Validated header data.
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra  Columns the caller sets itself, e.g. the
     *                                       recurring schedule's id and period.
     */
    public function execute(array $header, array $lines, array $extra = []): Invoice
    {
        return DB::transaction(function () use ($header, $lines, $extra): Invoice {
            $attributes = $this->header->fromValidated($header) + $extra;

            // Numbered at creation, not at approval — see
            // InvoiceNumberAllocator. Inside the transaction so a rollback
            // returns the number rather than burning it.
            $attributes['number'] = $this->numbers->allocate(
                (string) $attributes['type'],
                $attributes['issue_date'],
            );

            $invoice = Invoice::create($attributes);

            $this->lines->replace($invoice, $lines);

            return $invoice;
        });
    }
}
