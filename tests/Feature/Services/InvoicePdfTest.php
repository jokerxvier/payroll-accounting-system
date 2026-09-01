<?php

declare(strict_types=1);

use App\Actions\Accounting\ApproveInvoice;
use App\Actions\Accounting\CreateInvoiceDraft;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\User;
use App\Services\Accounting\InvoicePdf;
use App\Services\Accounting\InvoiceQrCode;
use Database\Seeders\AccountingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Multitenancy\Models\Tenant;

/*
 * The pay link on the printed invoice.
 *
 * The document is the thing a parent keeps — attached to an email, saved, or
 * printed — so it has to carry its own way to pay. What needs pinning is not
 * that the link renders, but WHICH documents get one: offering payment on a
 * supplier's bill, an unissued proforma, or an invoice already settled invites
 * a payment nobody asked for.
 *
 * The other half is that no state of the document may stop it rendering.
 * `MintInvoicePayToken` answers two of these questions by throwing, which is
 * right for someone pressing a button and wrong for a PDF — a draft proforma
 * still has to print.
 *
 * **Minting is the observable difference, and that is what these assert.**
 * dompdf compresses its streams, so the URL is not greppable in the output;
 * whether a token was minted is both visible and the thing that actually
 * decides whether a public URL now exists. The rendering is exercised for real
 * alongside it, because a template that throws is exactly what this guards.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    $this->income = ChartOfAccount::query()->where('code', '4100')->firstOrFail();
    $this->expense = ChartOfAccount::query()->where('code', '5100')->firstOrFail();

    $this->customer = Contact::factory()->create([
        'name' => 'Dela Cruz Family',
        'is_customer' => true,
        'is_supplier' => true,
    ]);

    $this->actor = User::factory()->create();
    $this->actor->syncRoles(['accountant']);
});

/** Renders for real, and returns the bytes so the caller can assert on them. */
function renderInvoicePdf(Invoice $invoice): string
{
    $bytes = app(InvoicePdf::class)->bytes($invoice);

    expect($bytes)->not->toBe('')
        ->and($bytes)->toStartWith('%PDF');

    return $bytes;
}

function pdfDraftInvoice(string $type = Invoice::TYPE_SALES): Invoice
{
    return app(CreateInvoiceDraft::class)->execute(
        [
            'type' => $type,
            'contact_id' => test()->customer->id,
            'issue_date' => '2026-08-01',
            'due_date' => '2026-08-16',
            'is_vat_inclusive' => false,
        ],
        [[
            'description' => 'Tuition',
            'quantity' => '1',
            'unit_price_centavos' => 500_000,
            'account_id' => $type === Invoice::TYPE_SALES
                ? test()->income->id
                : test()->expense->id,
            'tax_rate_id' => null,
        ]],
    );
}

function pdfIssuedInvoice(string $type = Invoice::TYPE_SALES): Invoice
{
    return app(ApproveInvoice::class)->execute(
        pdfDraftInvoice($type),
        (int) test()->actor->getKey(),
    );
}

/* ── Which documents carry a link ────────────────────────────────────── */

it('mints a pay token when printing an issued sales invoice', function () {
    $invoice = pdfIssuedInvoice();

    expect($invoice->pay_token)->toBeNull();

    renderInvoicePdf($invoice);

    expect($invoice->fresh()->pay_token)->not->toBeNull();
});

it('leaves a draft alone — no token, and it still prints', function () {
    // The regression that matters. `MintInvoicePayToken` throws for a draft,
    // so a PDF that called it unguarded would fail to render a proforma
    // rather than simply leaving the block out.
    $invoice = pdfDraftInvoice();

    renderInvoicePdf($invoice);

    expect($invoice->fresh()->pay_token)->toBeNull();
});

it('leaves a purchase bill alone', function () {
    // The supplier's document. There is nobody for this school to collect
    // from, so a pay link on it is meaningless.
    $bill = pdfIssuedInvoice(Invoice::TYPE_PURCHASE);

    renderInvoicePdf($bill);

    expect($bill->fresh()->pay_token)->toBeNull();
});

it('stops offering payment once the invoice is settled', function () {
    $invoice = pdfIssuedInvoice();

    $invoice->forceFill([
        'amount_paid_centavos' => $invoice->total_centavos,
        'status' => Invoice::STATUS_PAID,
    ])->save();

    renderInvoicePdf($invoice->fresh());

    expect($invoice->fresh()->pay_token)->toBeNull();
});

it('never churns a token that is already out there', function () {
    // A minted token is the invoice's public identity for good — re-minting
    // would break a link already sitting in a parent's messages.
    $invoice = pdfIssuedInvoice();

    renderInvoicePdf($invoice);
    $token = $invoice->fresh()->pay_token;

    renderInvoicePdf($invoice->fresh());

    expect($invoice->fresh()->pay_token)->toBe($token);
});

/* ── End to end ──────────────────────────────────────────────────────── */

it('puts a clickable link into the finished PDF, not just the markup', function () {
    // The guard the view-level tests cannot give: they pass `payUrl` in
    // themselves, so they would still pass if `InvoicePdf` stopped supplying
    // it. This renders the real document and looks for the link annotation
    // dompdf writes for an anchor — the thing a reader actually clicks.
    $invoice = pdfIssuedInvoice();

    $bytes = renderInvoicePdf($invoice);
    $token = $invoice->fresh()->pay_token;

    expect($token)->not->toBeNull()
        ->and($bytes)->toContain('/URI')
        ->and($bytes)->toContain($token);
});

/* ── The block itself ────────────────────────────────────────────────── */

it('shows the link and the code on the document', function () {
    // Rendered through the view rather than read out of the compressed PDF
    // stream, so the assertion can see the markup the template produced.
    $invoice = pdfIssuedInvoice();
    $url = 'https://school.test/schools/default/pay/'.str_repeat('a', 40);

    $html = view('invoices.pdf', [
        'invoice' => $invoice,
        'seller' => Tenant::current(),
        'logo' => null,
        'payUrl' => $url,
        'payQr' => 'data:image/png;base64,AAAA',
    ])->render();

    // The rendered element, not the words: the stylesheet carries a
    // "Pay online" comment that is present whether the block renders or not.
    expect($html)->toContain('<table class="pay">')
        ->and($html)->toContain('data:image/png;base64,AAAA')
        ->and($html)->toContain('do not forward')
        // A clickable button, not printed text.
        ->and($html)->toContain('href="'.$url.'"')
        ->and($html)->toContain('Pay now');

    // The token appears exactly once, inside the href. Printing the URL as
    // well would put a 40-character bearer token in plain sight on a document
    // that gets forwarded, photographed and left on desks.
    expect(substr_count($html, $url))->toBe(1);
});

it('omits the block entirely when there is nothing to pay', function () {
    $invoice = pdfDraftInvoice();

    $html = view('invoices.pdf', [
        'invoice' => $invoice,
        'seller' => Tenant::current(),
        'logo' => null,
        'payUrl' => null,
        'payQr' => null,
    ])->render();

    expect($html)->not->toContain('<table class="pay">');
});

it('still prints the link when the code cannot be drawn', function () {
    // ext-imagick is not guaranteed on every host. A missing QR must cost the
    // reader the convenience of scanning, not the ability to pay.
    $invoice = pdfIssuedInvoice();
    $url = 'https://school.test/schools/default/pay/token';

    $html = view('invoices.pdf', [
        'invoice' => $invoice,
        'seller' => Tenant::current(),
        'logo' => null,
        'payUrl' => $url,
        'payQr' => null,
    ])->render();

    expect($html)->toContain('href="'.$url.'"')
        ->and($html)->toContain('Pay now')
        ->and($html)->toContain('reading this on screen');
});

/* ── The code itself ─────────────────────────────────────────────────── */

it('renders a scannable code as an embedded data URI', function () {
    // A data URI, not a path: dompdf runs with enable_remote off and would
    // render nothing at all for an http(s) source, silently.
    expect(app(InvoiceQrCode::class)->dataUri('https://example.test/pay/abc'))
        ->toStartWith('data:image/png;base64,');
})->skip(
    ! extension_loaded('imagick'),
    'ext-imagick draws the code; the PDF degrades to the bare URL without it.',
);

it('returns null rather than throwing on an empty target', function () {
    expect(app(InvoiceQrCode::class)->dataUri(''))->toBeNull();
});
