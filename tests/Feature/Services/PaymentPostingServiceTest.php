<?php

declare(strict_types=1);

use App\Actions\Accounting\ApplyPaymentAllocations;
use App\Actions\Accounting\PostPayment;
use App\Actions\Accounting\VoidPayment;
use App\Exceptions\ClosedAccountingPeriodException;
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
use App\Services\Accounting\PaymentPostingService;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * Phase 5 Slice 7 — payment → general ledger.
 *
 * The shape that matters: cash moves by the full amount, the control account
 * moves by what was allocated, and anything left over lands in advances
 * rather than driving a receivable negative.
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

function payAccount(string $code): ChartOfAccount
{
    return ChartOfAccount::query()->where('code', $code)->firstOrFail();
}

function paySystemAccount(string $systemCode): ChartOfAccount
{
    return ChartOfAccount::query()->where('system_code', $systemCode)->firstOrFail();
}

function payIssuedInvoice(int $totalCentavos, ?Contact $contact = null, string $type = Invoice::TYPE_SALES): Invoice
{
    $invoice = Invoice::factory()->create([
        'type' => $type,
        'contact_id' => ($contact ?? test()->customer)->id,
        'issue_date' => '2026-08-10',
        'status' => Invoice::STATUS_APPROVED,
        'number' => 'DOC-'.fake()->unique()->numerify('######'),
        'vatable_sales_centavos' => $totalCentavos,
        'total_centavos' => $totalCentavos,
    ]);

    InvoiceLine::factory()->forInvoice($invoice)->create([
        'account_id' => payAccount($type === Invoice::TYPE_SALES ? '4100' : '5300')->id,
        'unit_price_centavos' => $totalCentavos,
        'line_net_centavos' => $totalCentavos,
    ]);

    return $invoice->refresh();
}

function payDraft(int $amountCentavos, ?Contact $contact = null, string $type = Payment::TYPE_RECEIPT): Payment
{
    return Payment::factory()->create([
        'type' => $type,
        'contact_id' => ($contact ?? test()->customer)->id,
        'cash_account_id' => test()->cash->id,
        'payment_date' => '2026-08-15',
        'amount_centavos' => $amountCentavos,
    ]);
}

/** @param array<int, array{0: Invoice, 1: int}> $pairs */
function payAllocate(Payment $payment, array $pairs): Payment
{
    return app(ApplyPaymentAllocations::class)->execute($payment, array_map(
        static fn (array $pair): array => [
            'invoice_id' => $pair[0]->id,
            'amount_centavos' => $pair[1],
        ],
        $pairs,
    ));
}

function paymentPoster(): PaymentPostingService
{
    return app(PaymentPostingService::class);
}

/* ── Receipts ───────────────────────────────────────────────────────── */

it('debits cash and credits the receivable for a fully allocated receipt', function () {
    $invoice = payIssuedInvoice(500_000);
    $payment = payAllocate(payDraft(500_000), [[$invoice, 500_000]]);

    $entry = paymentPoster()->post($payment, $this->actor->id);
    $byAccount = $entry->lines()->get()->keyBy('account_id');

    expect($byAccount[$this->cash->id]->debit_centavos)->toBe(500_000)
        ->and($byAccount[paySystemAccount(ChartOfAccount::SYSTEM_AR_CONTROL)->id]->credit_centavos)->toBe(500_000)
        ->and($entry->lines()->count())->toBe(2)
        ->and($entry->total_debit_centavos)->toBe($entry->total_credit_centavos);
});

it('splits an overpayment between the receivable and advances', function () {
    // The decision this slice turns on: money received against no invoice is
    // a liability owed back in goods, not a receivable owed backwards.
    $invoice = payIssuedInvoice(300_000);
    $payment = payAllocate(payDraft(500_000), [[$invoice, 300_000]]);

    $entry = paymentPoster()->post($payment, $this->actor->id);
    $byAccount = $entry->lines()->get()->keyBy('account_id');

    $advances = paySystemAccount(ChartOfAccount::SYSTEM_CUSTOMER_ADVANCES);

    expect($byAccount[$this->cash->id]->debit_centavos)->toBe(500_000)
        ->and($byAccount[paySystemAccount(ChartOfAccount::SYSTEM_AR_CONTROL)->id]->credit_centavos)->toBe(300_000)
        ->and($byAccount[$advances->id]->credit_centavos)->toBe(200_000)
        ->and($advances->code)->toBe('2410')
        ->and($entry->total_debit_centavos)->toBe($entry->total_credit_centavos);
});

it('emits no receivable line for a payment allocated to nothing', function () {
    // A pure advance — a parent paying ahead of any invoice.
    $payment = payDraft(500_000);

    $entry = paymentPoster()->post($payment, $this->actor->id);
    $arId = paySystemAccount(ChartOfAccount::SYSTEM_AR_CONTROL)->id;

    expect($entry->lines()->count())->toBe(2)
        ->and($entry->lines()->where('account_id', $arId)->exists())->toBeFalse()
        ->and($entry->lines()
            ->where('account_id', paySystemAccount(ChartOfAccount::SYSTEM_CUSTOMER_ADVANCES)->id)
            ->value('credit_centavos'))->toBe(500_000);
});

it('emits no advances line when the payment is fully allocated', function () {
    $invoice = payIssuedInvoice(500_000);
    $payment = payAllocate(payDraft(500_000), [[$invoice, 500_000]]);

    $entry = paymentPoster()->post($payment, $this->actor->id);
    $advancesId = paySystemAccount(ChartOfAccount::SYSTEM_CUSTOMER_ADVANCES)->id;

    expect($entry->lines()->where('account_id', $advancesId)->exists())->toBeFalse();
});

it("honours a contact's own receivable account", function () {
    $override = payAccount('1210');
    $contact = Contact::factory()->create(['receivable_account_id' => $override->id]);
    $invoice = payIssuedInvoice(400_000, $contact);
    $payment = payAllocate(payDraft(400_000, $contact), [[$invoice, 400_000]]);

    $entry = paymentPoster()->post($payment, $this->actor->id);

    expect($entry->lines()->where('account_id', $override->id)->value('credit_centavos'))
        ->toBe(400_000)
        ->and($entry->lines()->where('account_id', paySystemAccount(ChartOfAccount::SYSTEM_AR_CONTROL)->id)->exists())
        ->toBeFalse();
});

/* ── Disbursements invert ───────────────────────────────────────────── */

it('inverts every side for a disbursement', function () {
    $supplier = Contact::factory()->supplier()->create();
    $bill = payIssuedInvoice(300_000, $supplier, Invoice::TYPE_PURCHASE);
    $payment = payAllocate(
        payDraft(500_000, $supplier, Payment::TYPE_DISBURSEMENT),
        [[$bill, 300_000]],
    );

    $entry = paymentPoster()->post($payment, $this->actor->id);
    $byAccount = $entry->lines()->get()->keyBy('account_id');

    $supplierAdvances = paySystemAccount(ChartOfAccount::SYSTEM_SUPPLIER_ADVANCES);

    expect($byAccount[$this->cash->id]->credit_centavos)->toBe(500_000)
        ->and($byAccount[paySystemAccount(ChartOfAccount::SYSTEM_AP_CONTROL)->id]->debit_centavos)->toBe(300_000)
        // An advance to a supplier is an asset — money they owe us in goods.
        ->and($byAccount[$supplierAdvances->id]->debit_centavos)->toBe(200_000)
        ->and($supplierAdvances->code)->toBe('1450')
        ->and($entry->total_debit_centavos)->toBe($entry->total_credit_centavos);
});

/* ── Traceability, idempotency, refusals ────────────────────────────── */

it('traces the entry back to the payment that caused it', function () {
    $payment = payDraft(500_000);
    $entry = paymentPoster()->post($payment, $this->actor->id);

    expect($entry->source_type)->toBe(Payment::class)
        ->and($entry->source_id)->toBe($payment->id)
        ->and($payment->refresh()->journal_entry_id)->toBe($entry->id);
});

it('never posts the same payment twice', function () {
    $payment = payDraft(500_000);

    $first = paymentPoster()->post($payment, $this->actor->id);
    $second = paymentPoster()->post($payment->refresh(), $this->actor->id);

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('refuses a payment that moves nothing', function () {
    $payment = payDraft(0);

    expect(fn () => paymentPoster()->post($payment, $this->actor->id))
        ->toThrow(DomainException::class, 'moves no money');
});

it('refuses to post into a closed period', function () {
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);

    expect(fn () => paymentPoster()->post(payDraft(500_000), $this->actor->id))
        ->toThrow(ClosedAccountingPeriodException::class);
});

it('leaves nothing behind when posting is refused', function () {
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);
    $payment = payDraft(500_000);

    try {
        paymentPoster()->post($payment, $this->actor->id);
    } catch (ClosedAccountingPeriodException) {
        // expected
    }

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(JournalEntryLine::query()->count())->toBe(0)
        ->and($payment->refresh()->journal_entry_id)->toBeNull();
});

/* ── PostPayment ties the status, the ledger, and the balances ──────── */

it('posts, stamps, and settles in one step', function () {
    $invoice = payIssuedInvoice(500_000);
    $payment = payAllocate(payDraft(500_000), [[$invoice, 500_000]]);

    $posted = app(PostPayment::class)->execute($payment, $this->actor->id);

    expect($posted->status)->toBe(Payment::STATUS_POSTED)
        ->and($posted->posted_by_user_id)->toBe($this->actor->id)
        ->and($posted->journal_entry_id)->not->toBeNull()
        ->and($invoice->refresh()->status)->toBe(Invoice::STATUS_PAID);
});

it('fails the whole post when the ledger refuses it', function () {
    // A receipt the books reject is money the school cannot account for, so
    // unlike payroll there is no reason to let the record stand.
    $invoice = payIssuedInvoice(500_000);
    $payment = payAllocate(payDraft(500_000), [[$invoice, 500_000]]);
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);

    expect(fn () => app(PostPayment::class)->execute($payment, $this->actor->id))
        ->toThrow(ClosedAccountingPeriodException::class);

    expect($payment->refresh()->status)->toBe(Payment::STATUS_DRAFT)
        ->and($payment->journal_entry_id)->toBeNull()
        ->and($invoice->refresh()->status)->toBe(Invoice::STATUS_APPROVED);
});

it('refuses to post anything that is not a draft', function () {
    $payment = Payment::factory()->posted()->create([
        'contact_id' => $this->customer->id,
        'cash_account_id' => $this->cash->id,
    ]);

    expect(fn () => app(PostPayment::class)->execute($payment, $this->actor->id))
        ->toThrow(DomainException::class, 'Only a draft can be posted');
});

/* ── VoidPayment ────────────────────────────────────────────────────── */

it('reverses the entry and keeps both sides posted', function () {
    $invoice = payIssuedInvoice(500_000);
    $payment = payAllocate(payDraft(500_000), [[$invoice, 500_000]]);
    app(PostPayment::class)->execute($payment, $this->actor->id);

    app(VoidPayment::class)->execute($payment->refresh(), $this->actor->id, 'Cheque bounced');

    // Original plus reversal, both posted, offsetting to zero.
    expect(JournalEntry::query()->count())->toBe(2)
        ->and(JournalEntry::query()->where('status', JournalEntry::STATUS_POSTED)->count())->toBe(2)
        ->and(JournalEntryLine::query()->sum('debit_centavos'))
        ->toBe(JournalEntryLine::query()->sum('credit_centavos'))
        ->and($payment->refresh()->status)->toBe(Payment::STATUS_VOIDED)
        ->and($payment->void_reason)->toBe('Cheque bounced');
});

it('nets the cash account back to zero after a void', function () {
    $payment = payDraft(500_000);
    app(PostPayment::class)->execute($payment, $this->actor->id);
    app(VoidPayment::class)->execute($payment->refresh(), $this->actor->id);

    $cashLines = JournalEntryLine::query()->where('account_id', $this->cash->id)->get();

    expect($cashLines->sum('debit_centavos') - $cashLines->sum('credit_centavos'))->toBe(0);
});

it('refuses to void a draft', function () {
    expect(fn () => app(VoidPayment::class)->execute(payDraft(500_000), $this->actor->id))
        ->toThrow(DomainException::class, 'Only a posted payment can be voided');
});
