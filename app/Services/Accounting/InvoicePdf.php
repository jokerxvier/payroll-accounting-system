<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\Invoice;
use App\Models\Pas\School;
use App\Services\SchoolLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Spatie\Multitenancy\Models\Tenant;

/**
 * One way to build the printable face of an invoice.
 *
 * Extracted when the document started being emailed as well as downloaded.
 * Two call sites rendering the same view with their own copy of the paper
 * size, the logo rule and the filename is two documents that drift — and the
 * one nobody looks at is the one attached to a customer's email.
 *
 * **Font subsetting is on, and it is not a micro-optimisation.**
 * `barryvdh/laravel-dompdf` ships `enable_font_subsetting => false` in its own
 * config, overriding dompdf's default of true, so every PDF embeds each font
 * whole. That is 1.38 MB for a one-page invoice against 32 KB with subsetting
 * — a 43x difference, invisible on a download and very visible on an
 * attachment that has to reach a parent's phone. Set per render rather than by
 * publishing `config/dompdf.php`, because that file's other default is
 * `enable_remote = false`, which is what stops dompdf fetching remote images
 * and is worth leaving exactly where it is.
 */
final class InvoicePdf
{
    public function __construct(
        private readonly SchoolLogo $logos,
    ) {}

    /**
     * The rendered document, ready to stream or write.
     */
    public function make(Invoice $invoice): PdfDocument
    {
        $invoice->loadMissing([
            'contact',
            'lines.account:id,code,name',
            'lines.taxRate:id,code,name,rate_bps,type',
        ]);

        $tenant = Tenant::current();
        $school = $tenant instanceof School ? $tenant : null;

        return Pdf::setOption(['isFontSubsettingEnabled' => true])
            ->loadView('invoices.pdf', [
                'invoice' => $invoice,
                'seller' => $tenant,
                // A data URI: dompdf runs with enable_remote off and would
                // refuse a URL silently, rendering nothing at all.
                'logo' => $this->logos->dataUri($school),
            ])
            ->setPaper('a4', 'portrait');
    }

    /**
     * The raw bytes, for anything that is not an HTTP response.
     */
    public function bytes(Invoice $invoice): string
    {
        return $this->make($invoice)->output();
    }

    /**
     * What the file is called once it leaves this app.
     *
     * The document's own number, because that is what the recipient will refer
     * to when they write back about it. A draft has one too — numbers are
     * allocated at creation here — but it is named as a draft so a proforma
     * sitting in a folder cannot be mistaken for the issued document.
     */
    public function filename(Invoice $invoice): string
    {
        $noun = $invoice->isSales() ? 'invoice' : 'bill';

        if ($invoice->number === null) {
            return sprintf('%s-draft-%d.pdf', $noun, $invoice->getKey());
        }

        return $invoice->isDraft()
            ? sprintf('%s-%s-draft.pdf', $noun, $invoice->number)
            : sprintf('%s-%s.pdf', $noun, $invoice->number);
    }
}
