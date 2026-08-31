<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use Database\Seeders\AccountingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;

/**
 * What the printed invoice says.
 *
 * `InvoiceControllerTest` asserts the print route returns a PDF, which a
 * template that had lost its totals would still satisfy. These render the
 * Blade to HTML and read it, the same way `PayslipDocumentTest` does — the
 * two documents share a design and should share the standard of proof.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    $this->customer = Contact::factory()->create([
        'name' => 'Dela Cruz Family',
        'tin' => '221-445-889-000',
        'address' => '14 Sampaguita Street, Dasmariñas',
    ]);
});

function documentInvoice(array $overrides = []): Invoice
{
    $income = ChartOfAccount::query()->where('code', '4100')->firstOrFail();
    $vat = TaxRate::query()->where('code', 'VAT_12_SALES')->firstOrFail();

    $invoice = Invoice::factory()->create(array_merge([
        'type' => Invoice::TYPE_SALES,
        'status' => Invoice::STATUS_APPROVED,
        'number' => 'INV-2026-00004',
        'contact_id' => test()->customer->id,
        'student_name' => 'Francesca Inez Dela Cruz',
        'issue_date' => '2026-08-15',
        'due_date' => '2026-09-15',
        'reference' => 'PO-8813',
        'vatable_sales_centavos' => 1_000_000,
        'vat_exempt_sales_centavos' => 0,
        'zero_rated_sales_centavos' => 0,
        'vat_centavos' => 120_000,
        'total_centavos' => 1_120_000,
        'amount_paid_centavos' => 0,
    ], $overrides));

    InvoiceLine::factory()->for($invoice)->create([
        'line_number' => 1,
        'description' => 'Tuition — August 2026',
        'quantity' => '1.0000',
        'unit_price_centavos' => 1_000_000,
        'account_id' => $income->id,
        'tax_rate_id' => $vat->id,
        'line_net_centavos' => 1_000_000,
        'line_tax_centavos' => 120_000,
    ]);

    return $invoice->load(['contact', 'lines.account', 'lines.taxRate']);
}

function renderInvoice(Invoice $invoice, ?School $seller = null, ?string $logo = null): string
{
    return View::make('invoices.pdf', [
        'invoice' => $invoice,
        'seller' => $seller,
        'logo' => $logo,
    ])->render();
}

it('names the seller and prints its TIN, which a BIR invoice must carry', function () {
    $seller = School::factory()->make([
        'registered_name' => 'Mindhearts Montessori School',
        'tin' => '009-123-456-000',
        'business_address' => 'Gen. Trias Drive, Dasmariñas',
    ]);

    expect(renderInvoice(documentInvoice(), $seller))
        ->toContain('Mindhearts Montessori School')
        ->toContain('009-123-456-000')
        ->toContain('Gen. Trias Drive');
});

it('prints nothing where the school has no registration details', function () {
    // Those are facts about a client's registration, not defaults the
    // software may invent.
    $html = renderInvoice(documentInvoice(), School::factory()->make([
        'registered_name' => null,
        'tin' => null,
        'business_address' => null,
    ]));

    // Matched on the markup, not the string: `org-role` also names a rule in
    // the shared stylesheet, so a bare substring check passes on the CSS.
    //
    // The customer's own TIN still prints. It is the seller's registration
    // that is absent, so the masthead TIN line and the issuer block go.
    expect($html)->not->toContain('<p class="org-role">')
        ->not->toContain('Issued by')
        ->toContain('221-445-889-000');
});

it('names the student the charges are for', function () {
    // A parent settling two children's fees cannot tell the invoices apart
    // from the payer's name alone.
    expect(renderInvoice(documentInvoice()))
        ->toContain('Charges for')
        ->toContain('Francesca Inez Dela Cruz');
});

it('marks a draft as not issued', function () {
    $draft = documentInvoice(['status' => Invoice::STATUS_DRAFT, 'number' => null]);

    expect(renderInvoice($draft))
        ->toContain('Draft — not issued')
        ->toContain('It reaches the books only when it is approved');
});

it('does not mark an issued document as a draft', function () {
    expect(renderInvoice(documentInvoice()))
        ->not->toContain('not issued')
        ->toContain('INV-2026-00004');
});

it('stamps a voided document and says why', function () {
    $voided = documentInvoice([
        'status' => Invoice::STATUS_VOIDED,
        'void_reason' => 'Raised against the wrong student.',
    ]);

    expect(renderInvoice($voided))
        ->toContain('Void')
        ->toContain('Why this was voided')
        ->toContain('Raised against the wrong student.')
        ->toContain('no longer claims payment');
});

it('shows the balance due once something has been received', function () {
    $part = documentInvoice(['amount_paid_centavos' => 500_000]);

    expect(renderInvoice($part))
        ->toContain('Balance due')
        ->toContain('₱6,200.00')
        ->toContain('₱5,000.00');
});

it('says nothing about a balance on an unpaid invoice', function () {
    // A "Paid ₱0.00 / Balance ₱11,200.00" pair on a fresh invoice reads as a
    // part payment that went missing.
    expect(renderInvoice(documentInvoice()))->not->toContain('Balance due');
});

it('lists only the sales buckets that carry something', function () {
    // An ordinary VATable sale is not padded with two zero rows.
    $html = renderInvoice(documentInvoice());

    expect($html)
        ->toContain('VATable sales')
        ->not->toContain('VAT-exempt sales')
        ->not->toContain('Zero-rated sales');
});

it('keeps the three sales buckets apart when a document mixes them', function () {
    // The distinction between exempt and zero-rated is what a return is filed
    // from; collapsing them into a subtotal would lose it for good.
    $mixed = documentInvoice([
        'vat_exempt_sales_centavos' => 250_000,
        'zero_rated_sales_centavos' => 50_000,
        'total_centavos' => 1_420_000,
    ]);

    expect(renderInvoice($mixed))
        ->toContain('VATable sales')
        ->toContain('VAT-exempt sales')
        ->toContain('Zero-rated sales');
});

it('renders the logo as an embedded data URI, never a URL', function () {
    // dompdf runs with `enable_remote` off and refuses an http(s) image
    // silently, so a URL here would print nothing and say nothing.
    expect(renderInvoice(documentInvoice(), null, 'data:image/png;base64,AAAA'))
        ->toContain('src="data:image/png;base64,AAAA"');
});

it('shares the payslip’s document styles rather than carrying a copy', function () {
    // Both are documents someone outside the office keeps, and they drifted
    // once already.
    $html = renderInvoice(documentInvoice());

    expect($html)
        ->toContain('#1F3A5F')
        ->toContain('DejaVu Serif');
});
