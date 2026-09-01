<?php

declare(strict_types=1);

use App\Actions\Accounting\ApplyPaymentAllocations;
use App\Actions\Accounting\PostOpeningBalances;
use App\Actions\Accounting\PostPayment;
use App\Actions\Accounting\RecordOpeningItems;
use App\Actions\Accounting\VoidInvoice;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentAllocation;
use App\Models\Pas\School;
use App\Models\User;
use App\Services\Accounting\InvoiceBalanceService;
use App\Services\Accounting\Reports\LedgerReportService;
use App\Services\Accounting\Reports\OpeningItemReconciliation;
use App\Services\Accounting\Reports\OpeningItemReconciliationService;
use App\Services\Accounting\Reports\ReceivablesService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;
use Spatie\Multitenancy\Models\Tenant;

/*
 * Phase 5 Slice 9 — the documents behind the opening receivable.
 *
 * The cutover snapshot says AR was ₱326,000. These are the invoices that make
 * it up, and the properties worth pinning are the ones that make the two
 * agree rather than compete:
 *
 *   - an open item posts NOTHING; the ledger is byte-identical afterwards
 *   - it still reaches the ageing report, with no change to ReceivablesService
 *   - a payment against one draws down the control account the snapshot filled
 *   - the sub-ledger reconciles to that control account, and says so when it
 *     does not
 *
 * The first is the one that would be catastrophic to get wrong: posting these
 * would double every receivable a migrating school carries.
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
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->create([
        'code' => '2026-06',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    // July too: collections against an open item land after the cutover,
    // which is the whole point of recording them.
    AccountingPeriod::factory()->create([
        'code' => '2026-07',
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $this->payer = Contact::factory()->create([
        'name' => 'Dela Cruz Family',
        'is_customer' => true,
    ]);

    $this->actor = User::factory()->create();
    $this->actor->syncRoles(['accountant']);

    $this->cash = ChartOfAccount::query()->where('code', '1100')->firstOrFail();

    $this->cutover = CarbonImmutable::parse('2026-06-30');
});

function openingItems(): RecordOpeningItems
{
    return app(RecordOpeningItems::class);
}

function arControl(): ChartOfAccount
{
    return ChartOfAccount::query()
        ->where('system_code', ChartOfAccount::SYSTEM_AR_CONTROL)
        ->firstOrFail();
}

/**
 * Opens the books with a receivable, exactly as a migrating school would.
 *
 * The counter-entry is Retained Earnings via the plug, because prior trading
 * is what a receivable carried across a cutover represents.
 */
function openBooksWithReceivable(int $centavos): JournalEntry
{
    return app(PostOpeningBalances::class)->execute(
        test()->cutover,
        [[
            'account_id' => (int) arControl()->getKey(),
            'debit_centavos' => $centavos,
            'credit_centavos' => 0,
        ]],
        (int) test()->actor->getKey(),
        plugToRetainedEarnings: true,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function openItemRow(array $overrides = []): array
{
    return [
        'type' => Invoice::TYPE_SALES,
        'contact_id' => (int) test()->payer->getKey(),
        'number' => 'OLD-0042',
        'issue_date' => '2026-05-31',
        'due_date' => '2026-06-15',
        'total_centavos' => 500_000,
        'amount_paid_centavos' => 0,
        'student_name' => 'Juan Dela Cruz',
        ...$overrides,
    ];
}

/* ── The ledger must not move ────────────────────────────────────────── */

it('records the document without posting anything to the ledger', function () {
    // The catastrophic failure this guards: the snapshot already debited AR
    // for this money. Posting the invoice too would report the receivable
    // twice and overstate what the school is owed.
    openBooksWithReceivable(500_000);

    $entriesBefore = JournalEntry::query()->count();
    $arBefore = arClosingBalance();

    $recorded = openingItems()->execute([openItemRow()], (int) $this->actor->getKey());

    expect($recorded)->toHaveCount(1)
        ->and($recorded->first()->journal_entry_id)->toBeNull()
        ->and($recorded->first()->is_opening_item)->toBeTrue()
        ->and(JournalEntry::query()->count())->toBe($entriesBefore)
        ->and(arClosingBalance())->toBe($arBefore);
});

it('keeps the number the document was actually issued under', function () {
    // Drawing a fresh serial from the live counter would renumber a document
    // the client's customer already holds a copy of.
    openBooksWithReceivable(500_000);

    openingItems()->execute([openItemRow(['number' => '2024-0117'])], (int) $this->actor->getKey());

    expect(Invoice::query()->openingItems()->sole()->number)->toBe('2024-0117');
});

/* ── But the ageing report must see it ───────────────────────────────── */

it('reaches the ageing report with no change to ReceivablesService', function () {
    // The load-bearing claim of the whole design: `scopeOutstanding()` filters
    // on status and the paid/total comparison, never on `journal_entry_id`.
    openBooksWithReceivable(500_000);
    openingItems()->execute([openItemRow()], (int) $this->actor->getKey());

    $summary = app(ReceivablesService::class)->forRange(
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-06-30'),
        CarbonImmutable::parse('2026-07-20'),
    );

    $bucketTotal = array_sum(array_map(
        static fn (array $b): int => $b['centavos'],
        $summary->toArray()['aging'],
    ));

    expect($summary->outstandingCentavos)->toBe(500_000)
        ->and($bucketTotal)->toBe(500_000);
});

it('files an item with no due date under Current rather than Overdue', function () {
    // Absence of a due date means nobody agreed a deadline, not that the
    // deadline has passed.
    openBooksWithReceivable(500_000);
    openingItems()->execute(
        [openItemRow(['due_date' => null])],
        (int) $this->actor->getKey(),
    );

    $aging = collect(app(ReceivablesService::class)->forRange(
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-06-30'),
        CarbonImmutable::parse('2027-01-01'),
    )->toArray()['aging'])->keyBy('key');

    expect($aging['current']['centavos'])->toBe(500_000)
        ->and($aging['over_90']['centavos'])->toBe(0);
});

it('nets off what was collected before the cutover', function () {
    // The correction that matters. A ₱5,000 invoice with ₱2,000 already
    // received contributes ₱3,000 to the receivable, so the AR control holds
    // ₱3,000 — and the document has to be recorded at that balance. Carrying
    // the ₱5,000 gross would overstate the sub-ledger by every peso the
    // school collected before it moved.
    openBooksWithReceivable(300_000);
    openingItems()->execute(
        [openItemRow(['total_centavos' => 500_000, 'amount_paid_centavos' => 200_000])],
        (int) $this->actor->getKey(),
    );

    $invoice = Invoice::query()->openingItems()->sole();

    expect($invoice->total_centavos)->toBe(300_000)
        // Nothing has been received against it IN THIS system.
        ->and($invoice->amount_paid_centavos)->toBe(0)
        ->and($invoice->status)->toBe(Invoice::STATUS_SENT)
        ->and($invoice->balanceDue()->centavos())->toBe(300_000)
        // The original face value is kept as provenance, unreadable from the
        // figures alone once they are netted.
        ->and($invoice->notes)->toContain('5,000.00')
        ->and($invoice->notes)->toContain('2,000.00');

    // And it ties, which summing the gross would not have.
    expect(reconciliationFor('receivable')->differenceCentavos())->toBe(0);
});

it('does not drift as payments arrive after the cutover', function () {
    // The reason the balance is netted at record time rather than reconciled
    // from the live remainder: `amount_paid_centavos` grows with every
    // receipt taken here, while the control balance is read as at a cutover
    // that never moves. A remainder-based figure would tie on import day and
    // slide apart from then on.
    openBooksWithReceivable(300_000);
    openingItems()->execute(
        [openItemRow(['total_centavos' => 500_000, 'amount_paid_centavos' => 200_000])],
        (int) $this->actor->getKey(),
    );

    $invoice = Invoice::query()->openingItems()->sole();

    $payment = Payment::factory()->create([
        'type' => Payment::TYPE_RECEIPT,
        'contact_id' => $this->payer->getKey(),
        'cash_account_id' => $this->cash->getKey(),
        'payment_date' => '2026-07-15',
        'amount_centavos' => 120_000,
    ]);

    app(ApplyPaymentAllocations::class)->execute($payment, [[
        'invoice_id' => $invoice->getKey(),
        'amount_centavos' => 120_000,
    ]]);
    app(PostPayment::class)->execute($payment->refresh(), (int) $this->actor->getKey());

    expect($invoice->fresh()->amount_paid_centavos)->toBe(120_000)
        // Still ties: the reconciliation reads the brought-forward figure,
        // which a later receipt does not touch.
        ->and(reconciliationFor('receivable')->differenceCentavos())->toBe(0);
});

/* ── The sub-ledger and the control account are actually wired ───────── */

it('draws the control balance down when an open item is paid', function () {
    // The proof the two sides are connected rather than merely displayed
    // together. The snapshot put ₱5,000 into AR with no document behind it;
    // paying the document that explains it must relieve that same account,
    // because `PaymentPostingService` credits AR resolved from the payment's
    // CONTACT rather than from the invoice's (absent) journal entry.
    openBooksWithReceivable(500_000);
    openingItems()->execute([openItemRow()], (int) $this->actor->getKey());

    expect(arClosingBalanceAt('2026-07-31'))->toBe(500_000);

    $invoice = Invoice::query()->openingItems()->sole();

    $payment = Payment::factory()->create([
        'type' => Payment::TYPE_RECEIPT,
        'contact_id' => $this->payer->getKey(),
        'cash_account_id' => $this->cash->getKey(),
        'payment_date' => '2026-07-15',
        'amount_centavos' => 200_000,
    ]);

    app(ApplyPaymentAllocations::class)->execute($payment, [[
        'invoice_id' => $invoice->getKey(),
        'amount_centavos' => 200_000,
    ]]);

    app(PostPayment::class)->execute($payment->refresh(), (int) $this->actor->getKey());

    expect(arClosingBalanceAt('2026-07-31'))->toBe(300_000)
        ->and($invoice->fresh()->amount_paid_centavos)->toBe(200_000)
        ->and($invoice->fresh()->status)->toBe(Invoice::STATUS_PARTIALLY_PAID);
});

it('keeps its sent status when a payment against it is undone', function () {
    // `InvoiceBalanceService::statusFor()` falls back to `sent` only when
    // `sent_at` is set. Without the cutover stamp, voiding a receipt would
    // quietly demote a document the school has chased for months to
    // `approved` — which reads as "never sent".
    openBooksWithReceivable(500_000);
    openingItems()->execute([openItemRow()], (int) $this->actor->getKey());

    $invoice = Invoice::query()->openingItems()->sole();

    expect($invoice->sent_at)->not->toBeNull();

    app(InvoiceBalanceService::class)->recompute($invoice);

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_SENT);
});

/* ── Reconciliation ──────────────────────────────────────────────────── */

it('reports the sub-ledger tying to the control account', function () {
    openBooksWithReceivable(500_000);
    openingItems()->execute([openItemRow()], (int) $this->actor->getKey());

    $row = reconciliationFor('receivable');

    expect($row->controlCentavos)->toBe(500_000)
        ->and($row->itemsCentavos)->toBe(500_000)
        ->and($row->differenceCentavos())->toBe(0)
        ->and($row->isReconciled())->toBeTrue();
});

it('names the difference when the old system did not agree with itself', function () {
    // Deliberately not a refusal. A gap means the client's previous ledger
    // and its own sub-ledger disagreed, which is a finding they need rather
    // than a reason they cannot migrate.
    openBooksWithReceivable(500_000);
    openingItems()->execute(
        [openItemRow(['total_centavos' => 320_000])],
        (int) $this->actor->getKey(),
    );

    $row = reconciliationFor('receivable');

    expect($row->differenceCentavos())->toBe(180_000)
        ->and($row->isReconciled())->toBeFalse();
});

/* ── Refusals ────────────────────────────────────────────────────────── */

it('refuses to record anything before the books are opened', function () {
    // Without a cutover there is no control balance to reconcile against,
    // and the items would describe a receivable nothing states.
    openingItems()->execute([openItemRow()], (int) $this->actor->getKey());
})->throws(DomainException::class, 'have not been opened yet');

it('refuses a document dated after the cutover', function () {
    openBooksWithReceivable(500_000);

    openingItems()->execute(
        [openItemRow(['issue_date' => '2026-07-05'])],
        (int) $this->actor->getKey(),
    );
})->throws(DomainException::class, 'after the books were opened');

it('refuses a second import while items are standing', function () {
    openBooksWithReceivable(500_000);
    openingItems()->execute([openItemRow()], (int) $this->actor->getKey());

    openingItems()->execute(
        [openItemRow(['number' => 'OLD-0043'])],
        (int) $this->actor->getKey(),
    );
})->throws(DomainException::class, 'already recorded');

it('refuses an empty set', function () {
    openBooksWithReceivable(500_000);

    openingItems()->execute([], (int) $this->actor->getKey());
})->throws(DomainException::class, 'nothing to record');

/* ── Lifecycle ───────────────────────────────────────────────────────── */

it('can be voided despite carrying no journal entry', function () {
    // `VoidInvoice` reverses the invoice's entry. An open item has none, and
    // the action must not trip over that.
    openBooksWithReceivable(500_000);
    openingItems()->execute([openItemRow()], (int) $this->actor->getKey());

    $invoice = Invoice::query()->openingItems()->sole();

    app(VoidInvoice::class)->execute(
        $invoice,
        (int) $this->actor->getKey(),
        'Recorded in error',
    );

    expect($invoice->fresh()->status)->toBe(Invoice::STATUS_VOIDED);
});

it('cannot be approved, because it was never a draft', function () {
    // The guard that stops it ever posting: `ApproveInvoice` refuses anything
    // that is not a draft, and an open item is created already issued.
    openBooksWithReceivable(500_000);
    openingItems()->execute([openItemRow()], (int) $this->actor->getKey());

    $invoice = Invoice::query()->openingItems()->sole();

    expect($invoice->isDraft())->toBeFalse()
        ->and($invoice->isMutable())->toBeFalse()
        ->and($invoice->isIssued())->toBeTrue();
});

/* ── Tenancy ─────────────────────────────────────────────────────────── */

it('never lets one school see another school\'s open items', function () {
    openBooksWithReceivable(500_000);
    openingItems()->execute([openItemRow()], (int) $this->actor->getKey());

    $other = School::factory()->create();
    $other->makeCurrent();

    expect(Invoice::query()->openingItems()->count())->toBe(0);

    Tenant::forgetCurrent();
});

/* ── Helpers ─────────────────────────────────────────────────────────── */

function arClosingBalance(): int
{
    return arClosingBalanceAt('2026-06-30');
}

function arClosingBalanceAt(string $asAt): int
{
    $trialBalance = app(LedgerReportService::class)
        ->trialBalance(null, CarbonImmutable::parse($asAt));

    foreach ($trialBalance->rows as $row) {
        if ($row->accountId === (int) arControl()->getKey()) {
            return $row->closingNaturalCentavos();
        }
    }

    return 0;
}

function reconciliationFor(string $key): OpeningItemReconciliation
{
    foreach (app(OpeningItemReconciliationService::class)->forCurrentSchool() as $row) {
        if ($row->key === $key) {
            return $row;
        }
    }

    throw new RuntimeException("No {$key} reconciliation row.");
}
