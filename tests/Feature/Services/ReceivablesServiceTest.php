<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentAllocation;
use App\Models\Pas\School;
use App\Services\Accounting\Reports\ReceivablesService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Receivables: what was billed, what came in, what is still owed.
 *
 * The operational view. It reads documents and payments rather than the
 * ledger, and it is allowed to differ from the accounting dashboard — a draft
 * invoice is work an officer chases and is not yet revenue.
 *
 * The tests that earn their place are the ones where a wrong figure costs a
 * family money or hides a debt: a part-paid invoice counted at its full value,
 * a voided one still counted at all, an invoice with no due date reported as
 * overdue, or one family's three children ranked as three debtors.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PaymentAllocation::query()->withoutGlobalScopes()->delete();
    Payment::query()->withoutGlobalScopes()->delete();
    InvoiceLine::query()->withoutGlobalScopes()->delete();
    Invoice::query()->withoutGlobalScopes()->delete();
    Contact::query()->withoutGlobalScopes()->delete();

    $this->payer = Contact::factory()->create([
        'name' => 'Dela Cruz Family',
        'is_customer' => true,
    ]);
    $this->cash = ChartOfAccount::factory()->asset()->create([
        'code' => '1105',
        'is_cash_equivalent' => true,
    ]);
});

function receivables(): ReceivablesService
{
    return app(ReceivablesService::class);
}

/** @param array<string, mixed> $attributes */
function arInvoice(
    string $issueDate,
    int $totalCentavos,
    array $attributes = [],
): Invoice {
    return Invoice::factory()->create([
        'type' => Invoice::TYPE_SALES,
        'contact_id' => test()->payer->id,
        'status' => Invoice::STATUS_APPROVED,
        'issue_date' => CarbonImmutable::parse($issueDate),
        'due_date' => CarbonImmutable::parse($issueDate)->addDays(15),
        'total_centavos' => $totalCentavos,
        'amount_paid_centavos' => 0,
        ...$attributes,
    ]);
}

/** A posted receipt on a date, optionally applied to an invoice. */
function arReceipt(string $date, int $centavos, ?Invoice $invoice = null): Payment
{
    $payment = Payment::factory()->create([
        'type' => Payment::TYPE_RECEIPT,
        'contact_id' => test()->payer->id,
        'status' => Payment::STATUS_POSTED,
        'payment_date' => CarbonImmutable::parse($date),
        'posted_at' => CarbonImmutable::parse($date)->addDays(2),
        'amount_centavos' => $centavos,
        'cash_account_id' => test()->cash->id,
    ]);

    if ($invoice !== null) {
        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_centavos' => $centavos,
        ]);

        $invoice->forceFill([
            'amount_paid_centavos' => $invoice->amount_paid_centavos + $centavos,
            'status' => $invoice->amount_paid_centavos + $centavos >= $invoice->total_centavos
                ? Invoice::STATUS_PAID
                : Invoice::STATUS_PARTIALLY_PAID,
        ])->save();
    }

    return $payment;
}

function summaryOn(string $from, string $to, string $asOf)
{
    return receivables()->forRange(
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to),
        CarbonImmutable::parse($asOf),
    );
}

/** @param list<array{key: string, label: string, centavos: int}> $buckets */
function bucket(array $buckets, string $key): int
{
    foreach ($buckets as $b) {
        if ($b['key'] === $key) {
            return $b['centavos'];
        }
    }

    throw new RuntimeException("No bucket {$key}.");
}

/** @param list<array{key: string, label: string, count: int, centavos: int}> $slices */
function slice(array $slices, string $key): array
{
    foreach ($slices as $s) {
        if ($s['key'] === $key) {
            return $s;
        }
    }

    throw new RuntimeException("No status slice {$key}.");
}

/* ── The tiles ──────────────────────────────────────────────────────── */

it('bills on the issue date and collects on the payment date', function () {
    // Raised in August, paid in September: August's billing and September's
    // collections. Forcing both into one month is what joining them would do.
    $invoice = arInvoice('2026-08-10', 500_000);
    arReceipt('2026-09-05', 500_000, $invoice);

    $august = summaryOn('2026-08-01', '2026-08-31', '2026-09-30');

    expect($august->invoicedCentavos)->toBe(500_000)
        ->and($august->collectedCentavos)->toBe(0);

    $september = summaryOn('2026-09-01', '2026-09-30', '2026-09-30');

    expect($september->invoicedCentavos)->toBe(0)
        ->and($september->collectedCentavos)->toBe(500_000);
});

it('reports what is owed now, not what is owed out of this range', function () {
    // Outstanding is as-at, deliberately. Ranging it would answer "how much of
    // THIS MONTH's billing is unpaid", which is a much smaller and much less
    // useful number than what the school is owed.
    arInvoice('2026-07-10', 300_000);
    arInvoice('2026-08-10', 500_000);

    expect(summaryOn('2026-08-01', '2026-08-31', '2026-08-31')->outstandingCentavos)
        ->toBe(800_000);
});

it('counts only the unpaid remainder of a part-paid invoice', function () {
    // The single most costly mistake available here: a ₱10,000 invoice with
    // ₱9,000 paid owes ₱1,000, and reporting the total overstates the school's
    // exposure tenfold on that row.
    $invoice = arInvoice('2026-08-10', 1_000_000);
    arReceipt('2026-08-20', 900_000, $invoice);

    expect(summaryOn('2026-08-01', '2026-08-31', '2026-08-31')->outstandingCentavos)
        ->toBe(100_000);
});

it('ignores drafts and voided documents', function () {
    // A voided invoice keeps whatever `amount_paid_centavos` it had —
    // `InvoiceBalanceService::recompute()` returns early rather than zeroing —
    // so anything that did not go through `issued()` would read a stale figure.
    arInvoice('2026-08-10', 400_000, ['status' => Invoice::STATUS_DRAFT]);
    arInvoice('2026-08-11', 700_000, ['status' => Invoice::STATUS_VOIDED]);

    $summary = summaryOn('2026-08-01', '2026-08-31', '2026-08-31');

    expect($summary->invoicedCentavos)->toBe(0)
        ->and($summary->outstandingCentavos)->toBe(0);
});

it('ignores a draft payment, because no money has arrived', function () {
    $invoice = arInvoice('2026-08-10', 500_000);
    Payment::factory()->create([
        'type' => Payment::TYPE_RECEIPT,
        'contact_id' => $this->payer->id,
        'status' => Payment::STATUS_DRAFT,
        'payment_date' => CarbonImmutable::parse('2026-08-15'),
        'amount_centavos' => 500_000,
        'cash_account_id' => $this->cash->id,
    ]);

    expect(summaryOn('2026-08-01', '2026-08-31', '2026-08-31')->collectedCentavos)
        ->toBe(0)
        ->and($invoice->refresh()->amount_paid_centavos)->toBe(0);
});

it('reports collections gross, so the figures reconcile', function () {
    // `amount_centavos` is what the payer paid; `fee_centavos` is what a
    // gateway kept. Reporting net would leave a fully paid invoice looking
    // short by the fee and Collected + Outstanding no longer summing to
    // Invoiced. The fee is an expense, and belongs on the accounting side.
    $invoice = arInvoice('2026-08-10', 500_000);
    $payment = arReceipt('2026-08-20', 500_000, $invoice);
    $payment->forceFill(['fee_centavos' => 12_500])->save();

    $summary = summaryOn('2026-08-01', '2026-08-31', '2026-08-31');

    expect($summary->collectedCentavos)->toBe(500_000)
        ->and($summary->invoicedCentavos - $summary->collectedCentavos)
        ->toBe($summary->outstandingCentavos);
});

/* ── Ageing ─────────────────────────────────────────────────────────── */

it('buckets what is owed by how long it has been owed', function () {
    // Due dates 15 days after issue, read as at 2026-09-30.
    arInvoice('2026-09-20', 100_000);  // due 2026-10-05 — not yet due
    arInvoice('2026-09-01', 200_000);  // due 2026-09-16 — 14 days
    arInvoice('2026-07-20', 300_000);  // due 2026-08-04 — 57 days
    arInvoice('2026-06-20', 400_000);  // due 2026-07-05 — 87 days
    arInvoice('2026-04-01', 500_000);  // due 2026-04-16 — 167 days

    $aging = summaryOn('2026-09-01', '2026-09-30', '2026-09-30')->aging;

    expect(bucket($aging, 'current'))->toBe(100_000)
        ->and(bucket($aging, '1_30'))->toBe(200_000)
        ->and(bucket($aging, '31_60'))->toBe(300_000)
        ->and(bucket($aging, '61_90'))->toBe(400_000)
        ->and(bucket($aging, 'over_90'))->toBe(500_000);
});

it('ages the remainder, not the original total', function () {
    $invoice = arInvoice('2026-07-20', 1_000_000);
    arReceipt('2026-08-01', 750_000, $invoice);

    expect(bucket(summaryOn('2026-09-01', '2026-09-30', '2026-09-30')->aging, '31_60'))
        ->toBe(250_000);
});

it('treats an invoice with no due date as current, not overdue', function () {
    // The field is optional on the form. Its absence means the school set no
    // deadline, and calling that overdue would fill the tile with invoices
    // nobody ever agreed a date for.
    arInvoice('2026-01-05', 600_000, ['due_date' => null]);

    $summary = summaryOn('2026-09-01', '2026-09-30', '2026-09-30');

    expect(bucket($summary->aging, 'current'))->toBe(600_000)
        ->and($summary->overdueCentavos)->toBe(0);
});

it('sums the buckets to the outstanding tile', function () {
    // If these ever diverge, one of them is lying about the same money.
    arInvoice('2026-09-20', 100_000);
    arInvoice('2026-07-20', 300_000);
    arInvoice('2026-04-01', 500_000, ['due_date' => null]);

    $summary = summaryOn('2026-09-01', '2026-09-30', '2026-09-30');

    expect(array_sum(array_column($summary->aging, 'centavos')))
        ->toBe($summary->outstandingCentavos);
});

/* ── Status breakdown ───────────────────────────────────────────────── */

it('splits issued invoices by how they stand', function () {
    $paidInvoice = arInvoice('2026-08-01', 500_000);
    arReceipt('2026-08-05', 500_000, $paidInvoice);

    $part = arInvoice('2026-08-02', 400_000);
    arReceipt('2026-08-06', 100_000, $part);

    arInvoice('2026-09-25', 300_000);

    $statuses = summaryOn('2026-08-01', '2026-09-30', '2026-09-30')->statuses;

    expect(slice($statuses, 'paid')['count'])->toBe(1)
        ->and(slice($statuses, 'partially_paid')['count'])->toBe(1)
        ->and(slice($statuses, 'unpaid')['count'])->toBe(1);
});

it('reports overdue alongside the others rather than as a fourth slice', function () {
    // An overdue invoice is also unpaid or part-paid. Counting it as a
    // separate slice of the same whole would double-count the same peso and
    // total more than was ever billed.
    arInvoice('2026-06-01', 300_000);

    $statuses = summaryOn('2026-09-01', '2026-09-30', '2026-09-30')->statuses;

    expect(slice($statuses, 'unpaid')['count'])->toBe(1)
        ->and(slice($statuses, 'overdue')['count'])->toBe(1)
        // Overdue carries the REMAINDER; the others carry the invoice total.
        ->and(slice($statuses, 'overdue')['centavos'])->toBe(300_000);
});

/* ── Monthly ────────────────────────────────────────────────────────── */

it('returns a quiet month rather than leaving a gap in the chart', function () {
    arInvoice('2026-07-10', 300_000);
    arInvoice('2026-09-10', 400_000);

    $monthly = summaryOn('2026-07-01', '2026-09-30', '2026-09-30')->monthly;

    expect($monthly)->toHaveCount(3)
        ->and($monthly[1]['month'])->toBe('2026-08')
        ->and($monthly[1]['invoiced_centavos'])->toBe(0);
});

/* ── Top outstanding ────────────────────────────────────────────────── */

it('counts a family with three children as one debtor', function () {
    // `pas_contact_students` exists so one payer is one contact however many
    // children they have. Grouping by student would show the same debt three
    // times and rank a large family above a genuinely delinquent one.
    arInvoice('2026-08-01', 100_000, ['student_name' => 'Juan Dela Cruz']);
    arInvoice('2026-08-02', 200_000, ['student_name' => 'Sofia Dela Cruz']);
    arInvoice('2026-08-03', 300_000, ['student_name' => 'Miguel Dela Cruz']);

    $rows = summaryOn('2026-08-01', '2026-08-31', '2026-08-31')->topOutstanding;

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['contact_name'])->toBe('Dela Cruz Family')
        ->and($rows[0]['outstanding_centavos'])->toBe(600_000)
        ->and($rows[0]['students'])->toHaveCount(3);
});

it('ranks by what is owed and reports the oldest debt', function () {
    $other = Contact::factory()->create(['name' => 'Reyes Family', 'is_customer' => true]);

    arInvoice('2026-08-01', 100_000);
    Invoice::factory()->create([
        'type' => Invoice::TYPE_SALES,
        'contact_id' => $other->getKey(),
        'status' => Invoice::STATUS_APPROVED,
        'issue_date' => CarbonImmutable::parse('2026-06-01'),
        'due_date' => CarbonImmutable::parse('2026-06-16'),
        'total_centavos' => 900_000,
        'amount_paid_centavos' => 0,
    ]);

    $rows = summaryOn('2026-08-01', '2026-08-31', '2026-09-30')->topOutstanding;

    expect($rows[0]['contact_name'])->toBe('Reyes Family')
        ->and($rows[0]['oldest_due_date'])->toBe('2026-06-16')
        ->and($rows[0]['days_overdue'])->toBe(106)
        ->and($rows[0]['status'])->toBe('overdue');
});

it('calls a part-paid payer who is late overdue, not partially paid', function () {
    // Overdue outranks part-paid: someone who has paid something and is three
    // months late is a collections problem, not a healthy account.
    $invoice = arInvoice('2026-05-01', 400_000);
    arReceipt('2026-05-10', 100_000, $invoice);

    $rows = summaryOn('2026-09-01', '2026-09-30', '2026-09-30')->topOutstanding;

    expect($rows[0]['status'])->toBe('overdue')
        ->and($rows[0]['outstanding_centavos'])->toBe(300_000);
});

/* ── Tenancy ────────────────────────────────────────────────────────── */

it('never reads another school\'s receivables', function () {
    $other = School::factory()->create();
    $theirPayer = Contact::factory()->create([
        'school_id' => $other->getKey(),
        'name' => 'Another School Family',
        'is_customer' => true,
    ]);
    Invoice::factory()->create([
        'school_id' => $other->getKey(),
        'type' => Invoice::TYPE_SALES,
        'contact_id' => $theirPayer->getKey(),
        'status' => Invoice::STATUS_APPROVED,
        'issue_date' => CarbonImmutable::parse('2026-08-10'),
        'due_date' => CarbonImmutable::parse('2026-08-25'),
        'total_centavos' => 9_999_900,
        'amount_paid_centavos' => 0,
    ]);

    arInvoice('2026-08-10', 100_000);

    $summary = summaryOn('2026-08-01', '2026-08-31', '2026-08-31');

    expect($summary->invoicedCentavos)->toBe(100_000)
        ->and($summary->outstandingCentavos)->toBe(100_000)
        ->and($summary->topOutstanding)->toHaveCount(1);
});

/* ── Where this view parts company with the ledger ───────────────────── */

it('counts invoices only, so an opening balance is not double-reported', function () {
    // The accounting dashboard's Receivables reads the AR control account,
    // which also carries the cutover snapshot posted at Slice 9 — money genuinely
    // owed from before the system existed, with no invoice document behind it.
    //
    // This view deliberately does NOT see that: it lists what an officer can
    // open and chase. The two dashboards differ by exactly the opening balance,
    // and a reader who does not know that reads it as a bug — so it is pinned
    // here rather than left to be rediscovered.
    arInvoice('2026-08-10', 100_000);

    $summary = summaryOn('2026-08-01', '2026-08-31', '2026-08-31');

    expect($summary->outstandingCentavos)->toBe(100_000)
        ->and($summary->topOutstanding)->toHaveCount(1);
});
