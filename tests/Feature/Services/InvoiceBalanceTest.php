<?php

declare(strict_types=1);

use App\Actions\Accounting\ApplyPaymentAllocations;
use App\Actions\Accounting\PostPayment;
use App\Actions\Accounting\VoidInvoice;
use App\Actions\Accounting\VoidPayment;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentAllocation;
use App\Models\Pas\TaxRate;
use App\Models\User;
use App\Services\Accounting\InvoiceBalanceService;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * Phase 5 Slice 7 — allocation invariants and invoice balances.
 *
 * The property that matters here is that an invoice's paid amount always
 * equals the sum of what posted payments actually applied to it — never more,
 * never stale, and never influenced by a payment that has not reached the
 * ledger.
 */

beforeEach(function (): void {
    PaymentAllocation::query()->withoutGlobalScopes()->delete();
    Payment::query()->withoutGlobalScopes()->delete();
    InvoiceLine::query()->withoutGlobalScopes()->delete();
    Invoice::query()->withoutGlobalScopes()->delete();
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    Contact::query()->withoutGlobalScopes()->delete();
    TaxRate::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    $this->actor = User::factory()->create();
    $this->customer = Contact::factory()->create(['name' => 'Dela Cruz Family']);
    $this->cash = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
});

function balances(): InvoiceBalanceService
{
    return app(InvoiceBalanceService::class);
}

/**
 * Named for payments rather than just `allocator()`: Pest helpers in test
 * files are plain global functions, so a name generic enough to collide with
 * another file's helper fatals the whole Feature suite at load time.
 */
function paymentAllocator(): ApplyPaymentAllocations
{
    return app(ApplyPaymentAllocations::class);
}

/** An issued sales invoice for the given total, with one matching line. */
function issuedInvoice(int $totalCentavos, ?Contact $contact = null): Invoice
{
    $contact ??= test()->customer;

    $invoice = Invoice::factory()->create([
        'contact_id' => $contact->id,
        'issue_date' => '2026-08-10',
        'status' => Invoice::STATUS_APPROVED,
        'number' => 'SI-'.fake()->unique()->numerify('######'),
        'vatable_sales_centavos' => $totalCentavos,
        'total_centavos' => $totalCentavos,
    ]);

    InvoiceLine::factory()->forInvoice($invoice)->create([
        'account_id' => ChartOfAccount::query()->where('code', '4100')->value('id'),
        'unit_price_centavos' => $totalCentavos,
        'line_net_centavos' => $totalCentavos,
    ]);

    return $invoice->refresh();
}

function draftPayment(int $amountCentavos, ?Contact $contact = null): Payment
{
    return Payment::factory()->create([
        'contact_id' => ($contact ?? test()->customer)->id,
        'cash_account_id' => test()->cash->id,
        'payment_date' => '2026-08-15',
        'amount_centavos' => $amountCentavos,
    ]);
}

/** @param array<int, array{0: Invoice, 1: int}> $pairs */
function allocate(Payment $payment, array $pairs): Payment
{
    return paymentAllocator()->execute($payment, array_map(
        static fn (array $pair): array => [
            'invoice_id' => $pair[0]->id,
            'amount_centavos' => $pair[1],
        ],
        $pairs,
    ));
}

/* ── The status walk ────────────────────────────────────────────────── */

it('walks an invoice from approved to partially paid to paid', function () {
    $invoice = issuedInvoice(1_120_000);
    expect($invoice->status)->toBe(Invoice::STATUS_APPROVED);

    $first = draftPayment(500_000);
    allocate($first, [[$invoice, 500_000]]);
    app(PostPayment::class)->execute($first, $this->actor->id);

    $invoice->refresh();
    expect($invoice->status)->toBe(Invoice::STATUS_PARTIALLY_PAID)
        ->and($invoice->amount_paid_centavos)->toBe(500_000)
        ->and($invoice->balanceDue()->centavos())->toBe(620_000);

    $second = draftPayment(620_000);
    allocate($second, [[$invoice, 620_000]]);
    app(PostPayment::class)->execute($second, $this->actor->id);

    $invoice->refresh();
    expect($invoice->status)->toBe(Invoice::STATUS_PAID)
        ->and($invoice->balanceDue()->centavos())->toBe(0);
});

it('leaves an invoice untouched while the payment is still a draft', function () {
    // The subtle one. A payment somebody keyed but never committed must not
    // show a document as settled on the strength of an entry that never
    // reached the ledger.
    $invoice = issuedInvoice(1_120_000);
    $payment = draftPayment(1_120_000);

    allocate($payment, [[$invoice, 1_120_000]]);

    expect($payment->refresh()->allocated_centavos)->toBe(1_120_000)
        ->and($invoice->refresh()->amount_paid_centavos)->toBe(0)
        ->and($invoice->status)->toBe(Invoice::STATUS_APPROVED);
});

it('settles several invoices from one payment', function () {
    $a = issuedInvoice(300_000);
    $b = issuedInvoice(700_000);

    $payment = draftPayment(1_000_000);
    allocate($payment, [[$a, 300_000], [$b, 700_000]]);
    app(PostPayment::class)->execute($payment, $this->actor->id);

    expect($a->refresh()->status)->toBe(Invoice::STATUS_PAID)
        ->and($b->refresh()->status)->toBe(Invoice::STATUS_PAID)
        ->and($payment->refresh()->isFullyAllocated())->toBeTrue();
});

/* ── Over-allocation ────────────────────────────────────────────────── */

it('refuses to allocate more than an invoice still owes', function () {
    $invoice = issuedInvoice(300_000);
    $payment = draftPayment(500_000);

    expect(fn () => allocate($payment, [[$invoice, 400_000]]))
        ->toThrow(DomainException::class, 'only has 3000.00 outstanding');
});

it('refuses to over-allocate across two separate payments', function () {
    // The bound is what the invoice still owes, not what this one payment
    // has left — otherwise two payments could each legally pay the full
    // amount and the invoice would be settled twice.
    $invoice = issuedInvoice(300_000);

    $first = draftPayment(200_000);
    allocate($first, [[$invoice, 200_000]]);
    app(PostPayment::class)->execute($first, $this->actor->id);

    $second = draftPayment(200_000);

    expect(fn () => allocate($second, [[$invoice, 200_000]]))
        ->toThrow(DomainException::class, 'only has 1000.00 outstanding');
});

it('refuses to allocate more than the payment carries', function () {
    $a = issuedInvoice(600_000);
    $b = issuedInvoice(600_000);
    $payment = draftPayment(1_000_000);

    expect(fn () => allocate($payment, [[$a, 600_000], [$b, 600_000]]))
        ->toThrow(DomainException::class, 'has been allocated');
});

it('allows a payment to exceed its allocations', function () {
    // The remainder is an advance, which is legitimate — money arrives in
    // round numbers.
    $invoice = issuedInvoice(300_000);
    $payment = draftPayment(500_000);

    allocate($payment, [[$invoice, 300_000]]);

    expect($payment->refresh()->allocated_centavos)->toBe(300_000)
        ->and($payment->unallocated()->centavos())->toBe(200_000)
        ->and($payment->isFullyAllocated())->toBeFalse();
});

it('refuses a zero or negative allocation', function () {
    $invoice = issuedInvoice(300_000);
    $payment = draftPayment(500_000);

    expect(fn () => allocate($payment, [[$invoice, 0]]))
        ->toThrow(DomainException::class, 'positive amount');
});

/* ── What may be allocated against ──────────────────────────────────── */

it('refuses to pay a draft invoice', function () {
    $invoice = Invoice::factory()->create([
        'contact_id' => $this->customer->id,
        'issue_date' => '2026-08-10',
        'total_centavos' => 300_000,
    ]);

    expect(fn () => allocate(draftPayment(300_000), [[$invoice, 300_000]]))
        ->toThrow(DomainException::class, 'Only an issued document can be paid');
});

it('refuses to pay a voided invoice', function () {
    $invoice = issuedInvoice(300_000);
    $invoice->forceFill(['status' => Invoice::STATUS_VOIDED])->save();

    expect(fn () => allocate(draftPayment(300_000), [[$invoice, 300_000]]))
        ->toThrow(DomainException::class, 'Only an issued document can be paid');
});

it('refuses to settle a bill with a receipt', function () {
    $supplier = Contact::factory()->supplier()->create();
    $bill = Invoice::factory()->create([
        'type' => Invoice::TYPE_PURCHASE,
        'contact_id' => $supplier->id,
        'issue_date' => '2026-08-10',
        'status' => Invoice::STATUS_APPROVED,
        'number' => 'BILL-000001',
        'total_centavos' => 300_000,
    ]);

    expect(fn () => allocate(draftPayment(300_000, $supplier), [[$bill, 300_000]]))
        ->toThrow(DomainException::class, 'settles sales documents');
});

it("refuses to settle another contact's invoice", function () {
    $other = Contact::factory()->create(['name' => 'Santos Family']);
    $invoice = issuedInvoice(300_000, $other);

    expect(fn () => allocate(draftPayment(300_000), [[$invoice, 300_000]]))
        ->toThrow(DomainException::class, 'belongs to a different contact');
});

/* ── Re-allocation ──────────────────────────────────────────────────── */

it('replaces allocations rather than accumulating them', function () {
    $invoice = issuedInvoice(1_000_000);
    $payment = draftPayment(500_000);

    allocate($payment, [[$invoice, 500_000]]);
    allocate($payment, [[$invoice, 200_000]]);

    expect($payment->refresh()->allocated_centavos)->toBe(200_000)
        ->and($payment->allocations()->count())->toBe(1);
});

it('restores the balance of an invoice that is de-allocated', function () {
    $a = issuedInvoice(400_000);
    $b = issuedInvoice(400_000);

    $payment = draftPayment(400_000);
    allocate($payment, [[$a, 400_000]]);
    app(PostPayment::class)->execute($payment, $this->actor->id);
    expect($a->refresh()->status)->toBe(Invoice::STATUS_PAID);

    // Re-allocating a posted payment is refused, so this is the draft path:
    // a second payment moved from A to B before posting.
    $second = draftPayment(400_000);
    allocate($second, [[$b, 400_000]]);
    allocate($second, []);

    expect($second->refresh()->allocated_centavos)->toBe(0)
        ->and($b->refresh()->amount_paid_centavos)->toBe(0)
        ->and($b->status)->toBe(Invoice::STATUS_APPROVED);
});

it('merges two lines for the same invoice into one allocation', function () {
    $invoice = issuedInvoice(1_000_000);
    $payment = draftPayment(500_000);

    paymentAllocator()->execute($payment, [
        ['invoice_id' => $invoice->id, 'amount_centavos' => 200_000],
        ['invoice_id' => $invoice->id, 'amount_centavos' => 300_000],
    ]);

    expect($payment->refresh()->allocations()->count())->toBe(1)
        ->and($payment->allocated_centavos)->toBe(500_000);
});

it('refuses to change the allocations on a posted payment', function () {
    $invoice = issuedInvoice(500_000);
    $payment = draftPayment(500_000);
    allocate($payment, [[$invoice, 500_000]]);
    app(PostPayment::class)->execute($payment, $this->actor->id);

    expect(fn () => allocate($payment->refresh(), [[$invoice, 100_000]]))
        ->toThrow(DomainException::class, 'Only a draft can be edited');
});

/* ── Voiding restores balances without destroying history ───────────── */

it('restores an invoice balance when its payment is voided', function () {
    $invoice = issuedInvoice(1_000_000);

    $payment = draftPayment(400_000);
    allocate($payment, [[$invoice, 400_000]]);
    app(PostPayment::class)->execute($payment, $this->actor->id);
    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_PARTIALLY_PAID);

    app(VoidPayment::class)->execute($payment->refresh(), $this->actor->id, 'Cheque bounced');

    expect($invoice->refresh()->amount_paid_centavos)->toBe(0)
        ->and($invoice->status)->toBe(Invoice::STATUS_APPROVED)
        // The allocation survives as the record of what was applied. It
        // simply stops counting, because the payment is no longer posted.
        ->and(PaymentAllocation::query()->count())->toBe(1);
});

it('keeps other payments counting when one is voided', function () {
    $invoice = issuedInvoice(1_000_000);

    $kept = draftPayment(300_000);
    allocate($kept, [[$invoice, 300_000]]);
    app(PostPayment::class)->execute($kept, $this->actor->id);

    $doomed = draftPayment(200_000);
    allocate($doomed, [[$invoice, 200_000]]);
    app(PostPayment::class)->execute($doomed, $this->actor->id);

    expect($invoice->refresh()->amount_paid_centavos)->toBe(500_000);

    app(VoidPayment::class)->execute($doomed->refresh(), $this->actor->id);

    expect($invoice->refresh()->amount_paid_centavos)->toBe(300_000)
        ->and($invoice->status)->toBe(Invoice::STATUS_PARTIALLY_PAID);
});

/* ── The guard VoidInvoice has never been able to reach ─────────────── */

it('refuses to void an invoice that has a posted payment against it', function () {
    // Until this slice nothing could write amount_paid_centavos, so this
    // guard in VoidInvoice had never once fired.
    $invoice = issuedInvoice(500_000);
    $payment = draftPayment(200_000);
    allocate($payment, [[$invoice, 200_000]]);
    app(PostPayment::class)->execute($payment, $this->actor->id);

    expect(fn () => app(VoidInvoice::class)->execute($invoice->refresh(), $this->actor->id))
        ->toThrow(DomainException::class, 'Reverse the payment before voiding');
});

it('allows voiding once the payment is reversed', function () {
    $invoice = issuedInvoice(500_000);
    $payment = draftPayment(200_000);
    allocate($payment, [[$invoice, 200_000]]);
    app(PostPayment::class)->execute($payment, $this->actor->id);

    app(VoidPayment::class)->execute($payment->refresh(), $this->actor->id);
    $voided = app(VoidInvoice::class)->execute($invoice->refresh(), $this->actor->id, 'Billed in error');

    expect($voided->status)->toBe(Invoice::STATUS_VOIDED);
});

it('leaves a voided invoice voided even if an allocation still points at it', function () {
    $invoice = issuedInvoice(500_000);
    $payment = draftPayment(200_000);
    allocate($payment, [[$invoice, 200_000]]);

    $invoice->forceFill(['status' => Invoice::STATUS_VOIDED])->save();
    balances()->recompute($invoice);

    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_VOIDED);
});
