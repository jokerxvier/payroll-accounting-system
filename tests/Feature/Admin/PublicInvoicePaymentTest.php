<?php

declare(strict_types=1);

use App\Actions\Payments\MintInvoicePayToken;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentAllocation;
use App\Models\Pas\PaymentGatewaySetting;
use App\Models\Pas\School;
use App\Services\Accounting\InvoiceBalanceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * The public pay page — the first route in this application reachable
 * without a session.
 *
 * The properties that matter are all refusals. A visitor here is anonymous
 * and may be probing: the token is the only credential, the `/schools/{slug}/`
 * prefix is spoofable for a guest, and `BelongsToTenant` fails open when no
 * tenant is current. Every test below is a variation on "the wrong person
 * sees nothing, and cannot tell why".
 */

beforeEach(function (): void {
    ChartOfAccount::query()->withoutGlobalScopes()->delete();
    $this->seed(AccountingCatalogSeeder::class);

    $this->customer = Contact::factory()->customer()->create();

    $this->invoice = Invoice::factory()->create([
        'contact_id' => $this->customer->getKey(),
        'type' => Invoice::TYPE_SALES,
        'status' => Invoice::STATUS_APPROVED,
        'number' => 'INV-2026-00001',
        'issue_date' => CarbonImmutable::now()->toDateString(),
        'total_centavos' => 112_000,
        'amount_paid_centavos' => 0,
    ]);

    $this->token = app(MintInvoicePayToken::class)->execute($this->invoice);
});

/**
 * Settle an invoice the way the app actually settles one.
 *
 * `InvoiceBalanceService::remainingCentavosFor()` derives the outstanding
 * figure from allocations on POSTED payments — not from the cached
 * `amount_paid_centavos` header. Faking the header alone leaves the page
 * correctly reporting the money as still owed, which is the behaviour we
 * want and the reason this helper exists.
 */
function settleInvoiceInFull(Invoice $invoice): void
{
    $payment = Payment::factory()->receipt()->posted()->create([
        'contact_id' => $invoice->contact_id,
        'amount_centavos' => $invoice->total_centavos,
        'allocated_centavos' => $invoice->total_centavos,
    ]);

    PaymentAllocation::create([
        'payment_id' => $payment->getKey(),
        'invoice_id' => $invoice->getKey(),
        'amount_centavos' => $invoice->total_centavos,
    ]);

    app(InvoiceBalanceService::class)->recompute($invoice);
}

/* ── Reaching it ────────────────────────────────────────────────────── */

it('renders for a visitor with no session at all', function (): void {
    $this->get("/schools/default/pay/{$this->token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/invoice-payment', false)
            ->where('invoice.number', 'INV-2026-00001')
            ->where('invoice.balance_due_centavos', 112_000)
            ->where('paid', false));
});

it('offers no payment method until the school has configured a gateway', function (): void {
    $this->get("/schools/default/pay/{$this->token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('methods', []));
});

it('offers a method once a gateway is fully configured', function (): void {
    PaymentGatewaySetting::factory()->usable(
        ChartOfAccount::query()->where('code', '1110')->firstOrFail(),
        ChartOfAccount::query()->where('code', '5900')->firstOrFail(),
    )->create();

    $this->get("/schools/default/pay/{$this->token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('methods', ['paymongo']));
});

/* ── The refusals ───────────────────────────────────────────────────── */

it('404s on a token that does not exist', function (): void {
    $this->get('/schools/default/pay/'.str_repeat('x', 40))->assertNotFound();
});

it('404s on a token that belongs to a different school', function (): void {
    $other = School::factory()->create(['slug' => 'other', 'domain' => null]);

    $foreign = Invoice::factory()->create([
        'school_id' => $other->getKey(),
        'type' => Invoice::TYPE_SALES,
        'status' => Invoice::STATUS_APPROVED,
        'total_centavos' => 500_000,
        'pay_token' => str_repeat('f', 40),
    ]);

    // A real, live token — for someone else's document. The lookup matches on
    // school AND token together, which is the whole reason the slug is not
    // treated as the credential.
    $this->get('/schools/default/pay/'.$foreign->pay_token)->assertNotFound();

    expect($foreign->refresh()->status)->toBe(Invoice::STATUS_APPROVED);
});

it('404s on a draft invoice, giving nothing away', function (): void {
    $draft = Invoice::factory()->create([
        'contact_id' => $this->customer->getKey(),
        'type' => Invoice::TYPE_SALES,
        'status' => Invoice::STATUS_DRAFT,
        'pay_token' => str_repeat('d', 40),
    ]);

    $this->get('/schools/default/pay/'.$draft->pay_token)->assertNotFound();
});

it('404s on a voided invoice', function (): void {
    $this->invoice->forceFill(['status' => Invoice::STATUS_VOIDED])->save();

    $this->get("/schools/default/pay/{$this->token}")->assertNotFound();
});

/* ── Once it is settled ─────────────────────────────────────────────── */

it('reports a settled invoice as paid and stops asking for money', function (): void {
    settleInvoiceInFull($this->invoice);

    $this->get("/schools/default/pay/{$this->token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paid', true)
            ->where('invoice.balance_due_centavos', 0));
});

it('sends a payer away from checkout when there is nothing left to pay', function (): void {
    settleInvoiceInFull($this->invoice);

    $this->post("/schools/default/pay/{$this->token}/checkout")
        ->assertRedirect("/schools/default/pay/{$this->token}");
});

/* ── Minting ────────────────────────────────────────────────────────── */

it('mints a token once and never churns it', function (): void {
    // Re-issuing would silently break a link already in a parent's hands.
    $again = app(MintInvoicePayToken::class)->execute($this->invoice->refresh());

    expect($again)->toBe($this->token);
});

it('refuses to mint a link for a draft', function (): void {
    $draft = Invoice::factory()->create([
        'contact_id' => $this->customer->getKey(),
        'type' => Invoice::TYPE_SALES,
        'status' => Invoice::STATUS_DRAFT,
    ]);

    expect(fn () => app(MintInvoicePayToken::class)->execute($draft))
        ->toThrow(DomainException::class, 'Approve it first');
});

it('refuses to mint a link for a purchase bill', function (): void {
    $bill = Invoice::factory()->create([
        'contact_id' => Contact::factory()->supplier()->create()->getKey(),
        'type' => Invoice::TYPE_PURCHASE,
        'status' => Invoice::STATUS_APPROVED,
    ]);

    // A supplier's document is theirs; there is nobody for us to bill.
    expect(fn () => app(MintInvoicePayToken::class)->execute($bill))
        ->toThrow(DomainException::class, 'nobody to send a pay link to');
});
