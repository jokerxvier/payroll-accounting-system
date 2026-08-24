<?php

declare(strict_types=1);

use App\Actions\Accounting\ApproveInvoice;
use App\Exceptions\ClosedAccountingPeriodException;
use App\Exceptions\DocumentNumberUnavailableException;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\DocumentNumberSeries;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\TaxRate;
use App\Models\User;
use App\Services\Accounting\InvoicePostingService;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * Phase 5 Slice 5 — invoice → general ledger.
 *
 * The property that makes this trustworthy is the same one the payroll seam
 * has: whatever the calculator produced, the resulting entry balances, and
 * posting twice never doubles the books. The difference is what happens on
 * failure — an invoice is a numbered document handed to a third party, so a
 * ledger rejection must take the approval down with it rather than being
 * logged and shrugged off.
 */

beforeEach(function (): void {
    InvoiceLine::query()->withoutGlobalScopes()->delete();
    Invoice::query()->withoutGlobalScopes()->delete();
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    DocumentNumberSeries::query()->withoutGlobalScopes()->delete();
    Contact::query()->withoutGlobalScopes()->delete();
    TaxRate::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    // The real default chart and the four PH tax rates, so posting is
    // exercised against the accounts a school actually gets.
    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    DocumentNumberSeries::factory()->create(['next_number' => 1]);

    $this->actor = User::factory()->create();
});

function accountCode(string $code): ChartOfAccount
{
    return ChartOfAccount::query()->where('code', $code)->firstOrFail();
}

function systemAccount(string $systemCode): ChartOfAccount
{
    return ChartOfAccount::query()->where('system_code', $systemCode)->firstOrFail();
}

function rateCode(string $code): TaxRate
{
    return TaxRate::query()->where('code', $code)->firstOrFail();
}

/**
 * A draft sales invoice for ₱10,000 of tuition at 12% VAT, with totals
 * already consistent so the posting service can be tested on its own.
 */
function draftInvoice(array $attributes = []): Invoice
{
    $invoice = Invoice::factory()->create(array_merge([
        'issue_date' => '2026-08-15',
        'vatable_sales_centavos' => 1_000_000,
        'vat_centavos' => 120_000,
        'total_centavos' => 1_120_000,
    ], $attributes));

    InvoiceLine::factory()->forInvoice($invoice)->create([
        'description' => 'Tuition — August 2026',
        'account_id' => accountCode('4100')->id,
        'tax_rate_id' => rateCode('VAT_12_SALES')->id,
        'unit_price_centavos' => 1_000_000,
        'line_net_centavos' => 1_000_000,
        'line_tax_centavos' => 120_000,
    ]);

    return $invoice->refresh();
}

/**
 * Named for the invoice, not just `poster()`: JournalEntryPostingTest already
 * declares a `poster()`, and Pest helpers in test files are plain global
 * functions — two files declaring the same name fatals the whole Feature
 * suite before a single test runs. The same trap `authPerfAs()` fell into.
 */
function invoicePoster(): InvoicePostingService
{
    return app(InvoicePostingService::class);
}

function approver(): ApproveInvoice
{
    return app(ApproveInvoice::class);
}

/* ── The shape of the entry ─────────────────────────────────────────── */

it('posts a balanced entry for a sales invoice', function () {
    $entry = invoicePoster()->post(draftInvoice(), $this->actor->id);

    expect($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->total_debit_centavos)->toBe($entry->total_credit_centavos)
        ->and($entry->total_debit_centavos)->toBe(1_120_000);
});

it('debits receivables for the gross and credits income and output VAT', function () {
    $entry = invoicePoster()->post(draftInvoice(), $this->actor->id);

    $byAccount = $entry->lines()->get()->keyBy('account_id');

    $ar = $byAccount[systemAccount(ChartOfAccount::SYSTEM_AR_CONTROL)->id];
    $income = $byAccount[accountCode('4100')->id];
    $vat = $byAccount[systemAccount(ChartOfAccount::SYSTEM_VAT_OUTPUT)->id];

    expect($ar->debit_centavos)->toBe(1_120_000)
        ->and($ar->credit_centavos)->toBe(0)
        // The income account gets the net, never the gross — the VAT is the
        // BIR's money passing through, not revenue.
        ->and($income->credit_centavos)->toBe(1_000_000)
        ->and($vat->credit_centavos)->toBe(120_000);
});

it('traces the entry back to the invoice that caused it', function () {
    $invoice = draftInvoice();
    $entry = invoicePoster()->post($invoice, $this->actor->id);

    expect($entry->source_type)->toBe(Invoice::class)
        ->and($entry->source_id)->toBe($invoice->id)
        ->and($invoice->refresh()->journal_entry_id)->toBe($entry->id);
});

it('aggregates several lines sharing an account into one ledger line', function () {
    // The invoice lines stay the itemised record; the ledger records the
    // accounting effect, which is one figure against tuition income.
    $invoice = draftInvoice([
        'vatable_sales_centavos' => 3_000_000,
        'vat_centavos' => 360_000,
        'total_centavos' => 3_360_000,
    ]);

    foreach ([2, 3] as $lineNumber) {
        InvoiceLine::factory()->forInvoice($invoice)->create([
            'line_number' => $lineNumber,
            'account_id' => accountCode('4100')->id,
            'tax_rate_id' => rateCode('VAT_12_SALES')->id,
            'line_net_centavos' => 1_000_000,
            'line_tax_centavos' => 120_000,
        ]);
    }

    $entry = invoicePoster()->post($invoice, $this->actor->id);
    $incomeLines = $entry->lines()->where('account_id', accountCode('4100')->id)->get();

    expect($incomeLines)->toHaveCount(1)
        ->and($incomeLines->first()->credit_centavos)->toBe(3_000_000)
        ->and($entry->lines()->count())->toBe(3);
});

it('emits no VAT line for a fully exempt invoice', function () {
    $invoice = Invoice::factory()->create([
        'issue_date' => '2026-08-15',
        'vat_exempt_sales_centavos' => 500_000,
        'total_centavos' => 500_000,
    ]);

    InvoiceLine::factory()->forInvoice($invoice)->create([
        'account_id' => accountCode('4100')->id,
        'tax_rate_id' => rateCode('VAT_EXEMPT')->id,
        'line_net_centavos' => 500_000,
        'line_tax_centavos' => 0,
    ]);

    $entry = invoicePoster()->post($invoice->refresh(), $this->actor->id);
    $vatAccountId = systemAccount(ChartOfAccount::SYSTEM_VAT_OUTPUT)->id;

    expect($entry->lines()->count())->toBe(2)
        ->and($entry->lines()->where('account_id', $vatAccountId)->exists())->toBeFalse()
        ->and($entry->total_debit_centavos)->toBe(500_000);
});

/* ── Purchase bills invert ──────────────────────────────────────────── */

it('inverts every side for a purchase bill', function () {
    $supplier = Contact::factory()->supplier()->create();

    $invoice = Invoice::factory()->create([
        'type' => Invoice::TYPE_PURCHASE,
        'contact_id' => $supplier->id,
        'issue_date' => '2026-08-15',
        'vatable_sales_centavos' => 1_000_000,
        'vat_centavos' => 120_000,
        'total_centavos' => 1_120_000,
    ]);

    InvoiceLine::factory()->forInvoice($invoice)->create([
        'account_id' => accountCode('5300')->id,
        'tax_rate_id' => rateCode('VAT_12_PURCHASE')->id,
        'line_net_centavos' => 1_000_000,
        'line_tax_centavos' => 120_000,
    ]);

    $entry = invoicePoster()->post($invoice->refresh(), $this->actor->id);
    $byAccount = $entry->lines()->get()->keyBy('account_id');

    $ap = $byAccount[systemAccount(ChartOfAccount::SYSTEM_AP_CONTROL)->id];
    $expense = $byAccount[accountCode('5300')->id];
    $inputVat = $byAccount[systemAccount(ChartOfAccount::SYSTEM_VAT_INPUT)->id];

    expect($ap->credit_centavos)->toBe(1_120_000)
        ->and($expense->debit_centavos)->toBe(1_000_000)
        // Input VAT is an asset — creditable against output VAT, not an
        // expense.
        ->and($inputVat->debit_centavos)->toBe(120_000)
        ->and($entry->total_debit_centavos)->toBe($entry->total_credit_centavos);
});

/* ── The control account ────────────────────────────────────────────── */

it("falls back to the school's AR control when the contact has no override", function () {
    $entry = invoicePoster()->post(draftInvoice(), $this->actor->id);
    $arId = systemAccount(ChartOfAccount::SYSTEM_AR_CONTROL)->id;

    expect($entry->lines()->where('account_id', $arId)->value('debit_centavos'))
        ->toBe(1_120_000);
});

it("honours a contact's own receivable account", function () {
    // The fallback the contact register was built around, exercised from the
    // override side: a school tracking a scholarship fund separately gets
    // its own control account.
    $override = accountCode('1300');
    $contact = Contact::factory()->create(['receivable_account_id' => $override->id]);

    $entry = invoicePoster()->post(draftInvoice(['contact_id' => $contact->id]), $this->actor->id);

    expect($entry->lines()->where('account_id', $override->id)->value('debit_centavos'))
        ->toBe(1_120_000)
        ->and($entry->lines()->where('account_id', systemAccount(ChartOfAccount::SYSTEM_AR_CONTROL)->id)->exists())
        ->toBeFalse();
});

/* ── Refusals ───────────────────────────────────────────────────────── */

it('refuses an invoice whose stored total disagrees with its own buckets', function () {
    // A header the calculator never produced. PostJournalEntry would reject
    // this too, but with a message about debits and credits — the real fault
    // is that the invoice contradicts itself.
    $invoice = draftInvoice(['total_centavos' => 999_999]);

    expect(fn () => invoicePoster()->post($invoice, $this->actor->id))
        ->toThrow(DomainException::class, 'does not equal its own sales buckets');
});

it('refuses an invoice with no lines', function () {
    $invoice = Invoice::factory()->create(['issue_date' => '2026-08-15']);

    expect(fn () => invoicePoster()->post($invoice, $this->actor->id))
        ->toThrow(DomainException::class, 'no lines');
});

it('refuses an invoice that totals nothing', function () {
    $invoice = Invoice::factory()->create(['issue_date' => '2026-08-15']);
    InvoiceLine::factory()->forInvoice($invoice)->create([
        'account_id' => accountCode('4100')->id,
        'line_net_centavos' => 0,
        'line_tax_centavos' => 0,
    ]);

    expect(fn () => invoicePoster()->post($invoice->refresh(), $this->actor->id))
        ->toThrow(DomainException::class, 'totals zero');
});

it('refuses to post into a closed period', function () {
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);

    expect(fn () => invoicePoster()->post(draftInvoice(), $this->actor->id))
        ->toThrow(ClosedAccountingPeriodException::class);
});

it('leaves nothing behind when posting is refused', function () {
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);
    $invoice = draftInvoice();

    try {
        invoicePoster()->post($invoice, $this->actor->id);
    } catch (ClosedAccountingPeriodException) {
        // expected
    }

    // The draft entry created inside the transaction must not survive it.
    expect(JournalEntry::query()->count())->toBe(0)
        ->and(JournalEntryLine::query()->count())->toBe(0)
        ->and($invoice->refresh()->journal_entry_id)->toBeNull();
});

/* ── Idempotency ────────────────────────────────────────────────────── */

it('never posts the same invoice twice', function () {
    // What a retried job or a double-clicked button would cause. Doubling
    // here would double both the receivable and the VAT liability.
    $invoice = draftInvoice();

    $first = invoicePoster()->post($invoice, $this->actor->id);
    $second = invoicePoster()->post($invoice->refresh(), $this->actor->id);

    expect($second->id)->toBe($first->id)
        ->and(JournalEntry::query()->count())->toBe(1);
});

/* ── Approval ties numbering, totals, and posting together ──────────── */

it('numbers, posts, and approves in one step', function () {
    $invoice = approver()->execute(draftInvoice(), $this->actor->id);

    expect($invoice->status)->toBe(Invoice::STATUS_APPROVED)
        ->and($invoice->number)->toBe('SI-000001')
        ->and($invoice->approved_by_user_id)->toBe($this->actor->id)
        ->and($invoice->journal_entry_id)->not->toBeNull();
});

it('recalculates the totals rather than trusting what the draft stored', function () {
    // A draft saved with figures that no longer match its lines — the case
    // an edited tax rate creates. Issuing it as-is would put a wrong VAT
    // figure on a numbered document.
    $invoice = draftInvoice(['vatable_sales_centavos' => 1, 'vat_centavos' => 1, 'total_centavos' => 2]);

    $approved = approver()->execute($invoice, $this->actor->id);

    expect($approved->vatable_sales_centavos)->toBe(1_000_000)
        ->and($approved->vat_centavos)->toBe(120_000)
        ->and($approved->total_centavos)->toBe(1_120_000);
});

it('fails the approval when the ledger refuses the document', function () {
    // The rule that separates this from payroll. Payroll posting swallows a
    // ledger failure because staff still have to be paid; an invoice must
    // never be issued to a third party while the books reject it.
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);
    $invoice = draftInvoice();

    expect(fn () => approver()->execute($invoice, $this->actor->id))
        ->toThrow(ClosedAccountingPeriodException::class);

    expect($invoice->refresh()->status)->toBe(Invoice::STATUS_DRAFT)
        ->and($invoice->number)->toBeNull()
        ->and($invoice->journal_entry_id)->toBeNull();
});

it('returns the serial when the approval rolls back', function () {
    // The gapless guarantee, end to end: a failed approval must not burn a
    // BIR-controlled number.
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);

    try {
        approver()->execute(draftInvoice(), $this->actor->id);
    } catch (ClosedAccountingPeriodException) {
        // expected
    }

    expect(DocumentNumberSeries::query()->first()->next_number)->toBe(1);

    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_OPEN]);
    $approved = approver()->execute(draftInvoice(), $this->actor->id);

    // The number the failed attempt almost used.
    expect($approved->number)->toBe('SI-000001');
});

it('refuses to approve anything that is not a draft', function () {
    $invoice = Invoice::factory()->approved()->create();

    expect(fn () => approver()->execute($invoice, $this->actor->id))
        ->toThrow(DomainException::class, 'Only a draft can be approved');
});

it('refuses to approve an invoice with no lines', function () {
    $invoice = Invoice::factory()->create(['issue_date' => '2026-08-15']);

    expect(fn () => approver()->execute($invoice, $this->actor->id))
        ->toThrow(DomainException::class, 'Add at least one charge');
});

it('refuses to approve when no numbering series exists', function () {
    DocumentNumberSeries::query()->withoutGlobalScopes()->delete();

    expect(fn () => approver()->execute(draftInvoice(), $this->actor->id))
        ->toThrow(DocumentNumberUnavailableException::class);
});

it('draws a purchase bill from the bill series, not the invoice series', function () {
    // A supplier's document is not a BIR-controlled sales serial. It still
    // gets a number so bills are traceable, from its own counter.
    DocumentNumberSeries::factory()
        ->ofType(DocumentNumberSeries::TYPE_BILL, 'BILL-')
        ->create(['next_number' => 50]);

    $supplier = Contact::factory()->supplier()->create();
    $invoice = Invoice::factory()->create([
        'type' => Invoice::TYPE_PURCHASE,
        'contact_id' => $supplier->id,
        'issue_date' => '2026-08-15',
    ]);
    InvoiceLine::factory()->forInvoice($invoice)->create([
        'account_id' => accountCode('5300')->id,
        'tax_rate_id' => rateCode('VAT_12_PURCHASE')->id,
        'unit_price_centavos' => 1_000_000,
    ]);

    $approved = approver()->execute($invoice->refresh(), $this->actor->id);

    expect($approved->number)->toBe('BILL-000050')
        // The sales series is untouched.
        ->and(DocumentNumberSeries::query()
            ->where('document_type', DocumentNumberSeries::TYPE_SALES_INVOICE)
            ->value('next_number'))->toBe(1);
});
