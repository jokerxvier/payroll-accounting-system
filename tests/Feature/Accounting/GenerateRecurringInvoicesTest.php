<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateInvoiceDraft;
use App\Actions\Accounting\StartInvoiceSchedule;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\RecurringInvoice;
use App\Models\Pas\RecurringInvoiceLine;
use App\Models\Pas\RecurringInvoicePeriod;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use Database\Seeders\AccountingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * The nightly generator.
 *
 * The tests that earn their place here are the ones where a bug charges a real
 * family: billing twice, billing across schools, and billing someone whose
 * circumstances changed since the schedule was written.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);
    AccountingPeriod::factory()->create([
        'code' => '2026-09',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
    ]);

    $this->income = ChartOfAccount::query()->where('code', '4100')->firstOrFail();
    $this->customer = Contact::factory()->create([
        'name' => 'Dela Cruz Family',
        'is_customer' => true,
    ]);
});

/** A monthly schedule due on the 1st, with one ₱5,000 line. */
function recurringSchedule(array $attributes = [], array $lineAttributes = []): RecurringInvoice
{
    $schedule = RecurringInvoice::factory()->create(array_merge([
        'contact_id' => test()->customer->id,
        'starts_on' => '2026-08-01',
        'next_run_on' => '2026-08-01',
        'day_of_month' => 1,
    ], $attributes));

    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')->create(array_merge([
        'account_id' => test()->income->id,
        'unit_price_centavos' => 500_000,
        'tax_rate_id' => null,
    ], $lineAttributes));

    return $schedule->refresh();
}

function generateOn(string $date, array $options = []): int
{
    return test()->artisan('invoices:generate-recurring', array_merge([
        '--date' => $date,
    ], $options))->run();
}

/* ── The guarantee ───────────────────────────────────────────────────── */

it('raises a draft for a schedule that is due', function () {
    recurringSchedule();

    generateOn('2026-08-01');

    $invoice = Invoice::query()->sole();

    expect($invoice->status)->toBe(Invoice::STATUS_DRAFT)
        ->and($invoice->contact_id)->toBe($this->customer->id)
        ->and($invoice->total_centavos)->toBe(500_000)
        ->and($invoice->issue_date->toDateString())->toBe('2026-08-01')
        // due_days is 15 on the factory.
        ->and($invoice->due_date->toDateString())->toBe('2026-08-16');
});

it('never bills the same period twice, however often it runs', function () {
    // The one that matters. Three runs on the same day must leave one invoice.
    recurringSchedule();

    generateOn('2026-08-01');
    generateOn('2026-08-01');
    generateOn('2026-08-01');

    expect(Invoice::query()->count())->toBe(1)
        ->and(RecurringInvoicePeriod::query()->count())->toBe(1);
});

it('bills the next period once it comes round', function () {
    recurringSchedule();

    generateOn('2026-08-01');
    generateOn('2026-09-01');

    expect(Invoice::query()->count())->toBe(2)
        ->and(RecurringInvoicePeriod::query()->pluck('period')->all())
        ->toBe(['2026-08', '2026-09']);
});

it('keeps the claim when the draft it raised is deleted', function () {
    // Deleting a wrongly-generated draft must not hand the period back to
    // tonight's run. The operator's deletion has to survive until morning.
    recurringSchedule();
    generateOn('2026-08-01');

    Invoice::query()->sole()->delete();
    generateOn('2026-08-01');

    expect(Invoice::query()->count())->toBe(0)
        ->and(RecurringInvoicePeriod::query()->count())->toBe(1);
});

/* ── Tenancy ─────────────────────────────────────────────────────────── */

it('never generates one school\'s schedule into another school', function () {
    // BelongsToTenant fails open, so a generator running without a tenant would
    // read every school's schedules and file the invoices anywhere.
    $other = School::factory()->create(['is_active' => true]);
    recurringSchedule();

    generateOn('2026-08-01');

    $invoice = Invoice::query()->withoutGlobalScopes()->sole();

    expect($invoice->school_id)->toBe(School::query()->first()->id)
        ->and($invoice->school_id)->not->toBe($other->id);
});

/* ── Catch-up ────────────────────────────────────────────────────────── */

it('catches up every period it missed while the scheduler was down', function () {
    recurringSchedule();

    // Nothing ran in August. September's run owes both months.
    generateOn('2026-09-01');

    expect(Invoice::query()->count())->toBe(2)
        ->and(Invoice::query()->orderBy('issue_date')->pluck('issue_date')
            ->map(fn ($d) => $d->toDateString())->all())
        ->toBe(['2026-08-01', '2026-09-01']);
});

it('stops catching up once it is level', function () {
    recurringSchedule();

    generateOn('2026-09-01');
    generateOn('2026-09-01');

    expect(Invoice::query()->count())->toBe(2);
});

/* ── Schedules that should not fire ──────────────────────────────────── */

it('leaves a paused schedule alone', function () {
    recurringSchedule(['is_active' => false]);

    generateOn('2026-08-01');

    expect(Invoice::query()->count())->toBe(0);
});

it('stops at the end date', function () {
    recurringSchedule(['ends_on' => '2026-08-31']);

    generateOn('2026-09-01');

    expect(Invoice::query()->count())->toBe(1);
});

it('still bills the last period of a schedule that has already ended', function () {
    // Ended in August, first run is 1 September. August is still owed, and
    // excluding ended schedules from the query would lose that invoice.
    recurringSchedule(['ends_on' => '2026-08-31']);

    generateOn('2026-09-01');

    expect(Invoice::query()->count())->toBe(1)
        ->and(Invoice::query()->sole()->issue_date->toDateString())->toBe('2026-08-01');
});

it('retires a schedule once it has nothing left to bill', function () {
    // Otherwise it is re-selected every night for ever, to do nothing.
    $schedule = recurringSchedule(['ends_on' => '2026-08-31']);

    generateOn('2026-09-01');

    expect($schedule->refresh()->is_active)->toBeFalse();
});

it('does not run ahead of the start date', function () {
    recurringSchedule(['starts_on' => '2026-09-01', 'next_run_on' => '2026-09-01']);

    generateOn('2026-08-01');

    expect(Invoice::query()->count())->toBe(0);
});

/* ── The catch-up floor ──────────────────────────────────────────────── */

it('raises nothing before the books were opened, and says so', function () {
    // A draft backdated before the books opened can never be approved. It must
    // not be raised, must not be retried nightly, and must not be reported as
    // though it had been billed.
    School::query()->first()->update(['books_opened_on' => '2026-09-01']);
    recurringSchedule();

    generateOn('2026-09-01');

    $claim = RecurringInvoicePeriod::query()->where('period', '2026-08')->sole();

    expect(Invoice::query()->where('issue_date', '2026-08-01')->count())->toBe(0)
        // Claimed, so tonight's run does not try again — and the reason is on
        // the claim, which survives September being billed successfully.
        ->and($claim->invoice_id)->toBeNull()
        ->and($claim->note)->toContain('before this school\'s books opened');
});

it('does not count a suppressed period as one it generated', function () {
    School::query()->first()->update(['books_opened_on' => '2026-09-01']);
    $schedule = recurringSchedule();

    generateOn('2026-09-01');

    // September was raised; August was passed over. One generated, not two.
    expect(Invoice::query()->count())->toBe(1)
        ->and($schedule->refresh()->generated_count)->toBe(1);
});

/* ── Broken schedules are skipped, never fatal ───────────────────────── */

it('skips a payer who is no longer a customer, and says why', function () {
    $schedule = recurringSchedule();
    $this->customer->update(['is_customer' => false]);

    generateOn('2026-08-01');

    expect(Invoice::query()->count())->toBe(0)
        ->and($schedule->refresh()->last_error)->toContain('not marked as a customer');
});

it('bills everyone else even when one schedule is broken', function () {
    // A broken payer must not stop the other families being billed.
    $broken = Contact::factory()->create(['name' => 'Lapsed', 'is_customer' => false]);
    recurringSchedule(['contact_id' => $broken->id]);
    recurringSchedule();

    generateOn('2026-08-01');

    expect(Invoice::query()->count())->toBe(1);
});

it('skips a schedule with no lines', function () {
    $schedule = RecurringInvoice::factory()->create([
        'contact_id' => $this->customer->id,
        'starts_on' => '2026-08-01',
        'next_run_on' => '2026-08-01',
    ]);

    generateOn('2026-08-01');

    expect(Invoice::query()->count())->toBe(0)
        ->and($schedule->refresh()->last_error)->toContain('no lines');
});

it('clears the recorded error once a schedule works again', function () {
    $schedule = recurringSchedule();
    $this->customer->update(['is_customer' => false]);
    generateOn('2026-08-01');

    expect($schedule->refresh()->last_error)->not->toBeNull();

    $this->customer->update(['is_customer' => true]);
    generateOn('2026-08-01');

    expect($schedule->refresh()->last_error)->toBeNull();
});

/* ── What a draft must not do ────────────────────────────────────────── */

it('posts nothing to the ledger', function () {
    // The whole reason an unattended job is allowed to run at all.
    recurringSchedule();

    generateOn('2026-08-01');

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(Invoice::query()->sole()->journal_entry_id)->toBeNull();
});

it('writes nothing on a dry run', function () {
    recurringSchedule();

    generateOn('2026-08-01', ['--dry-run' => true]);

    expect(Invoice::query()->count())->toBe(0)
        ->and(RecurringInvoicePeriod::query()->count())->toBe(0);
});

/* ── Numbering and totals ────────────────────────────────────────────── */

it('numbers generated drafts from the school\'s own sequence', function () {
    recurringSchedule();
    recurringSchedule();

    generateOn('2026-08-01');

    expect(Invoice::query()->orderBy('id')->pluck('number')->all())
        ->toBe(['INV-2026-00001', 'INV-2026-00002']);
});

it('computes VAT from the tax rate at generation, not from the template', function () {
    $vat = TaxRate::query()->where('code', 'VAT_12_SALES')->firstOrFail();
    recurringSchedule([], ['tax_rate_id' => $vat->id]);

    generateOn('2026-08-01');

    $invoice = Invoice::query()->sole();

    expect($invoice->vatable_sales_centavos)->toBe(500_000)
        ->and($invoice->vat_centavos)->toBe(60_000)
        ->and($invoice->total_centavos)->toBe(560_000);
});

it('marks the invoice with the schedule that raised it', function () {
    $schedule = recurringSchedule();

    generateOn('2026-08-01');

    expect(Invoice::query()->sole()->recurring_invoice_id)->toBe($schedule->id);
});

/* ── A schedule started from an invoice someone typed ────────────────── */

/**
 * What the invoice form produces: a draft, and a schedule that repeats it.
 *
 * Built through the real action rather than by hand, because the claim it
 * writes is the thing under test — a fixture that forgot it would pass a test
 * the application would fail.
 */
function scheduleStartedFromInvoice(string $issueDate = '2026-08-01'): RecurringInvoice
{
    $invoice = app(CreateInvoiceDraft::class)->execute(
        [
            'type' => Invoice::TYPE_SALES,
            'contact_id' => test()->customer->id,
            'issue_date' => $issueDate,
            'due_date' => null,
            'is_vat_inclusive' => false,
        ],
        [[
            'description' => 'Tuition',
            'quantity' => '1',
            'unit_price_centavos' => 500_000,
            'account_id' => test()->income->id,
            'tax_rate_id' => null,
        ]],
    );

    return app(StartInvoiceSchedule::class)->execute($invoice, [
        'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
    ]);
}

it('does not bill the month the operator has already billed by hand', function () {
    // The schedule starts on its own cadence day, which
    // `RecurringInvoiceDatesTest` pins as billable that same day. What keeps
    // tonight's run off it is the cursor: `next_run_on` is seeded a period
    // ahead, so `scopeDueOn` does not select the schedule at all.
    //
    // The cursor alone is not the guarantee — see the catch-up test below,
    // which is where the claim earns its place.
    scheduleStartedFromInvoice('2026-08-01');

    generateOn('2026-08-01');

    expect(Invoice::query()->count())->toBe(1)
        ->and(RecurringInvoicePeriod::query()->count())->toBe(1);
});

it('bills the following month without going back for the first', function () {
    // **This is the test the period-0 claim exists for.** Once the cursor lets
    // the schedule through, `GenerateDueInvoices` works out where it has got
    // to from `periods()->count()` and catches up everything owed. Without the
    // claim that count is zero, so it starts at period 0 — August — and raises
    // it a second time: three invoices for two months, and a family billed
    // twice. Verified by removing the claim and watching this go to 3.
    scheduleStartedFromInvoice('2026-08-01');

    generateOn('2026-09-01');

    expect(Invoice::query()->count())->toBe(2)
        ->and(RecurringInvoicePeriod::query()->pluck('period')->all())
        ->toBe(['2026-08', '2026-09']);
});

it('ties the first claim to the invoice that was typed', function () {
    // `invoice_id` on the claim is what lets someone reading the schedule see
    // which document covered its first month.
    $schedule = scheduleStartedFromInvoice('2026-08-01');

    expect($schedule->periods()->sole()->invoice_id)
        ->toBe(Invoice::query()->sole()->id);
});

it('leaves the run cursor pointing at the next period', function () {
    $schedule = scheduleStartedFromInvoice('2026-08-01');

    expect($schedule->next_run_on->toDateString())->toBe('2026-09-01');
});

it('does not double-bill a mid-month invoice either', function () {
    // The 15th is the case the day-of-month derivation exists for: the cadence
    // day comes off the invoice, so period 0 lands exactly on it rather than
    // on a 1st nobody chose. Same catch-up trap as above — without the claim
    // the September run reaches back for 15 August.
    scheduleStartedFromInvoice('2026-08-15');

    generateOn('2026-08-15');

    expect(Invoice::query()->count())->toBe(1);

    generateOn('2026-09-15');

    expect(Invoice::query()->count())->toBe(2);
});
