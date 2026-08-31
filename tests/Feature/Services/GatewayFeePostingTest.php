<?php

declare(strict_types=1);

use App\Actions\Accounting\ApplyPaymentAllocations;
use App\Actions\Accounting\PostPayment;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * Gateway fees in the ledger.
 *
 * The whole reason the receipt carries GROSS and a separate fee: a ₱1,120
 * invoice paid online settles as ₱1,092 in the bank, and the ₱28 the gateway
 * kept is a cost of collecting — not a discount the customer received.
 *
 *     Dr Cash          1,092
 *     Dr Gateway fees     28
 *         Cr AR                1,120
 *
 * Post the net instead and the invoice sits at `partially_paid` forever, ₱28
 * short, with Aged Receivables slowly filling with fee-sized residue nobody
 * can collect. That is the failure this file exists to prevent.
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->forMonth(CarbonImmutable::parse('2026-08-01'))->create();

    $this->cash = ChartOfAccount::query()->where('code', '1110')->firstOrFail();
    $this->feeAccount = ChartOfAccount::query()->where('code', '5900')->firstOrFail();
    $this->actor = User::factory()->create();
    $this->customer = Contact::factory()->customer()->create();
});

/**
 * An issued invoice with a real receivable behind it.
 */
function feeTestInvoice(int $totalCentavos = 112_000): Invoice
{
    return Invoice::factory()->create([
        'contact_id' => test()->customer->getKey(),
        'type' => Invoice::TYPE_SALES,
        'status' => Invoice::STATUS_APPROVED,
        'number' => 'INV-2026-00001',
        'issue_date' => CarbonImmutable::parse('2026-08-15'),
        'total_centavos' => $totalCentavos,
        'amount_paid_centavos' => 0,
    ]);
}

/**
 * A gateway receipt: gross amount, fee split out, allocated to one invoice.
 */
function gatewayReceipt(Invoice $invoice, int $gross, int $fee): Payment
{
    $payment = Payment::factory()->receipt()->create([
        'contact_id' => test()->customer->getKey(),
        'payment_date' => CarbonImmutable::parse('2026-08-20'),
        'amount_centavos' => $gross,
        'fee_centavos' => $fee,
        'fee_account_id' => test()->feeAccount->getKey(),
        'cash_account_id' => test()->cash->getKey(),
        'method' => Payment::METHOD_ONLINE,
        'gateway_provider' => 'paymongo',
        'gateway_reference' => 'pay_abc123',
        'status' => Payment::STATUS_DRAFT,
    ]);

    app(ApplyPaymentAllocations::class)->execute($payment, [
        ['invoice_id' => $invoice->getKey(), 'amount_centavos' => $gross],
    ]);

    return $payment->refresh();
}

/** @return array<string, array{debit: int, credit: int}> */
function linesByAccountCode(JournalEntry $entry): array
{
    $out = [];

    foreach ($entry->lines()->get() as $line) {
        $code = ChartOfAccount::query()->find($line->account_id)?->code ?? '?';
        $out[$code] = [
            'debit' => $line->debit_centavos,
            'credit' => $line->credit_centavos,
        ];
    }

    return $out;
}

it('splits the fee out and still clears the invoice in full', function (): void {
    $invoice = feeTestInvoice(112_000);
    $payment = gatewayReceipt($invoice, 112_000, 2_800);

    $posted = app(PostPayment::class)->execute($payment, (int) $this->actor->getKey());
    $entry = JournalEntry::query()->findOrFail($posted->journal_entry_id);
    $lines = linesByAccountCode($entry);

    // Cash net of the fee…
    expect($lines['1110']['debit'])->toBe(109_200)
        // …the fee as an expense…
        ->and($lines['5900']['debit'])->toBe(2_800)
        // …and the receivable cleared by the GROSS.
        ->and($lines['1200']['credit'])->toBe(112_000)
        ->and($entry->total_debit_centavos)->toBe($entry->total_credit_centavos)
        ->and($entry->total_debit_centavos)->toBe(112_000);

    // The point of all of it: the invoice is settled, not 28 short.
    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_PAID)
        ->and($invoice->amount_paid_centavos)->toBe(112_000);
});

it('leaves a manually keyed payment posting exactly as before', function (): void {
    $invoice = feeTestInvoice(112_000);

    $payment = Payment::factory()->receipt()->create([
        'contact_id' => $this->customer->getKey(),
        'payment_date' => CarbonImmutable::parse('2026-08-20'),
        'amount_centavos' => 112_000,
        'cash_account_id' => $this->cash->getKey(),
        'status' => Payment::STATUS_DRAFT,
    ]);

    app(ApplyPaymentAllocations::class)->execute($payment, [
        ['invoice_id' => $invoice->getKey(), 'amount_centavos' => 112_000],
    ]);

    $posted = app(PostPayment::class)->execute($payment->refresh(), (int) $this->actor->getKey());
    $entry = JournalEntry::query()->findOrFail($posted->journal_entry_id);
    $lines = linesByAccountCode($entry);

    // No fee, no fee line, cash gross — the pre-existing two-line shape.
    expect($lines['1110']['debit'])->toBe(112_000)
        ->and($lines)->not->toHaveKey('5900')
        ->and($entry->lines()->count())->toBe(2);
});

it('routes an online overpayment to advances, fee still split', function (): void {
    $invoice = feeTestInvoice(100_000);
    // Paid 112,000 against a 100,000 invoice, gateway kept 2,800.
    $payment = Payment::factory()->receipt()->create([
        'contact_id' => $this->customer->getKey(),
        'payment_date' => CarbonImmutable::parse('2026-08-20'),
        'amount_centavos' => 112_000,
        'fee_centavos' => 2_800,
        'fee_account_id' => $this->feeAccount->getKey(),
        'cash_account_id' => $this->cash->getKey(),
        'method' => Payment::METHOD_ONLINE,
        'status' => Payment::STATUS_DRAFT,
    ]);

    app(ApplyPaymentAllocations::class)->execute($payment, [
        ['invoice_id' => $invoice->getKey(), 'amount_centavos' => 100_000],
    ]);

    $posted = app(PostPayment::class)->execute($payment->refresh(), (int) $this->actor->getKey());
    $entry = JournalEntry::query()->findOrFail($posted->journal_entry_id);
    $lines = linesByAccountCode($entry);

    expect($lines['1110']['debit'])->toBe(109_200)
        ->and($lines['5900']['debit'])->toBe(2_800)
        ->and($lines['1200']['credit'])->toBe(100_000)
        // The excess is a liability owed back, not a negative receivable.
        ->and($lines['2410']['credit'])->toBe(12_000)
        ->and($entry->total_debit_centavos)->toBe($entry->total_credit_centavos);
});

it('refuses to post a fee with nowhere to expense it', function (): void {
    $invoice = feeTestInvoice(112_000);

    $payment = Payment::factory()->receipt()->create([
        'contact_id' => $this->customer->getKey(),
        'payment_date' => CarbonImmutable::parse('2026-08-20'),
        'amount_centavos' => 112_000,
        'fee_centavos' => 2_800,
        'fee_account_id' => null,
        'cash_account_id' => $this->cash->getKey(),
        'status' => Payment::STATUS_DRAFT,
    ]);

    app(ApplyPaymentAllocations::class)->execute($payment, [
        ['invoice_id' => $invoice->getKey(), 'amount_centavos' => 112_000],
    ]);

    expect(fn () => app(PostPayment::class)->execute($payment->refresh(), (int) $this->actor->getKey()))
        ->toThrow(DomainException::class, 'no account to expense it to');
});
