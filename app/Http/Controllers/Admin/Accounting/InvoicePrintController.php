<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Pas\Invoice;
use App\Services\Accounting\InvoicePdf;
use Illuminate\Support\Facades\Gate;
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
 * approved is ordinary, and refusing would push people into screenshotting
 * the screen. The face marks it clearly, so a draft can never be mistaken
 * for an issued one.
 *
 * The document itself is built by {@see InvoicePdf}, which the invoice email
 * also attaches. Downloading and emailing must hand over the same paper.
 */
final class InvoicePrintController extends Controller
{
    public function __construct(
        private readonly InvoicePdf $pdf,
    ) {}

    public function show(Invoice $invoice): Response
    {
        Gate::authorize('print', $invoice);

        return $this->pdf->make($invoice)->stream($this->pdf->filename($invoice));
    }
}
