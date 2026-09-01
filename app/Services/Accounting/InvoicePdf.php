<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Actions\Payments\MintInvoicePayToken;
use App\Models\Pas\Invoice;
use App\Models\Pas\School;
use App\Services\SchoolLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use DomainException;
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
        private readonly MintInvoicePayToken $tokens,
        private readonly InvoiceQrCode $qr,
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

        $payUrl = $this->payUrlFor($invoice, $school);

        return Pdf::setOption(['isFontSubsettingEnabled' => true])
            ->loadView('invoices.pdf', [
                'invoice' => $invoice,
                'seller' => $tenant,
                // A data URI: dompdf runs with enable_remote off and would
                // refuse a URL silently, rendering nothing at all.
                'logo' => $this->logos->dataUri($school),
                'payUrl' => $payUrl,
                'payQr' => $payUrl === null ? null : $this->qr->dataUri($payUrl),
            ])
            ->setPaper('a4', 'portrait');
    }

    /**
     * The public pay link, for documents that can actually take a payment.
     *
     * Three conditions, and each excludes a document for its own reason: a
     * purchase bill is the supplier's paper and there is nobody to collect
     * from, a draft is a proforma that has not been issued to anyone, and a
     * settled invoice does not invite a second payment.
     *
     * The guard runs BEFORE the minter rather than relying on it, because
     * {@see MintInvoicePayToken} answers the same two questions by throwing —
     * which is right for a person pressing a button and wrong here, where a
     * draft proforma still has to print. The catch is belt-and-braces for the
     * same reason: no state of the document may stop it rendering.
     *
     * Note this MINTS a token when the invoice has none, so printing an issued
     * invoice creates a live public URL. That widens the invariant stated in
     * `InvoiceController::payUrl()` — tokens used to appear only when somebody
     * pressed Copy pay link or Send. The trade is deliberate: a link that
     * showed up on only some printed invoices, depending on whether a
     * colleague had pressed a button earlier, would be worse than one that is
     * always there. The write is idempotent, and a minted token is never
     * churned afterwards.
     */
    private function payUrlFor(Invoice $invoice, ?School $school): ?string
    {
        if ($school === null) {
            return null;
        }

        if (! $invoice->isSales() || ! $invoice->isIssued()) {
            return null;
        }

        if (! $invoice->balanceDue()->isPositive()) {
            return null;
        }

        try {
            $token = $this->tokens->execute($invoice);
        } catch (DomainException) {
            return null;
        }

        return route('public.pay.show', [
            'slug' => $school->slug,
            'token' => $token,
        ]);
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
