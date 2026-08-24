<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Pas\DocumentNumberSeries;
use App\Models\Pas\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Spatie\Multitenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the printable face of an invoice — Phase 5 Slice 5.
 *
 * Its own controller rather than another method on
 * {@see InvoiceController}: this one returns a PDF stream instead of an
 * Inertia response, and mixing the two response families in one class is
 * what makes a controller hard to read later.
 *
 * A draft prints too. Sending a customer a proforma before the document is
 * numbered is ordinary, and refusing would push people into screenshotting
 * the screen. The face marks it clearly, so an unnumbered document can never
 * be mistaken for an issued one.
 */
final class InvoicePrintController extends Controller
{
    public function show(Invoice $invoice): Response
    {
        Gate::authorize('print', $invoice);

        $invoice->load([
            'contact',
            'lines.account:id,code,name',
            'lines.taxRate:id,code,name,rate_bps,type',
        ]);

        $series = DocumentNumberSeries::query()
            ->where('document_type', $invoice->isSales()
                ? DocumentNumberSeries::TYPE_SALES_INVOICE
                : DocumentNumberSeries::TYPE_BILL)
            ->first();

        $filename = sprintf(
            '%s-%s.pdf',
            $invoice->isSales() ? 'invoice' : 'bill',
            $invoice->number ?? ('draft-'.$invoice->getKey()),
        );

        return Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'seller' => Tenant::current(),
            // Only passed so the permit footer can render when the client
            // has supplied ATP details. An unregistered series prints a
            // clean document instead of empty labels.
            'series' => $series?->hasAuthorityToPrint() === true ? $series : null,
        ])->setPaper('a4', 'portrait')->stream($filename);
    }
}
