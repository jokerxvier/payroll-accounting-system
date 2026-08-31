<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\School;
use App\Services\Accounting\Reports\LedgerSeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Income and expenses per month.
 *
 * The one aggregate the dashboard needs that the ledger reports cannot already
 * answer, so it re-proves the invariants from scratch rather than inheriting
 * them: posted only, ranged on the entry's own date, inclusive at both ends,
 * and never across schools.
 *
 * It also has one requirement the trial balance does not: the series must be
 * DENSE. A quiet month has to come back as zero, because a bar chart with a
 * gap where February should be reads as missing data, not as a quiet February.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    $this->cash = ChartOfAccount::factory()->asset()->create(['code' => '1100']);
    $this->receivable = ChartOfAccount::factory()->asset()->create(['code' => '1200']);
    $this->tuition = ChartOfAccount::factory()->income()->create(['code' => '4100']);
    $this->salaries = ChartOfAccount::factory()->expense()->create(['code' => '5100']);
});

function ledgerSeries(): LedgerSeriesService
{
    return app(LedgerSeriesService::class);
}

/** @param array<string, mixed> $attributes */
function seriesEntry(
    string $date,
    ChartOfAccount $debit,
    ChartOfAccount $credit,
    int $centavos,
    array $attributes = [],
): void {
    $entry = JournalEntry::factory()->create([
        'date' => CarbonImmutable::parse($date),
        'status' => JournalEntry::STATUS_POSTED,
        'posted_at' => CarbonImmutable::parse($date)->addDays(3),
        'total_debit_centavos' => $centavos,
        'total_credit_centavos' => $centavos,
        ...$attributes,
    ]);

    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $debit->getKey(),
        'debit_centavos' => $centavos,
        'credit_centavos' => 0,
        'line_number' => 1,
    ]);

    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $credit->getKey(),
        'debit_centavos' => 0,
        'credit_centavos' => $centavos,
        'line_number' => 2,
    ]);
}

/** @param list<array{month: string, label: string, income_centavos: int, expenses_centavos: int}> $series */
function monthIn(array $series, string $month): array
{
    foreach ($series as $row) {
        if ($row['month'] === $month) {
            return $row;
        }
    }

    throw new RuntimeException("No series row for {$month}.");
}

it('totals income and expenses into the month they were dated', function () {
    seriesEntry('2026-07-15', $this->receivable, $this->tuition, 300_000);
    seriesEntry('2026-08-10', $this->receivable, $this->tuition, 500_000);
    seriesEntry('2026-08-20', $this->salaries, $this->cash, 200_000);

    $series = ledgerSeries()->monthlyIncomeAndExpenses(
        CarbonImmutable::parse('2026-07-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect(monthIn($series, '2026-07')['income_centavos'])->toBe(300_000)
        ->and(monthIn($series, '2026-07')['expenses_centavos'])->toBe(0)
        ->and(monthIn($series, '2026-08')['income_centavos'])->toBe(500_000)
        ->and(monthIn($series, '2026-08')['expenses_centavos'])->toBe(200_000);
});

it('returns a quiet month as zero rather than leaving it out', function () {
    // A gap in a bar chart reads as missing data. February earned nothing and
    // has to say so.
    seriesEntry('2026-01-15', $this->receivable, $this->tuition, 300_000);
    seriesEntry('2026-03-15', $this->receivable, $this->tuition, 400_000);

    $series = ledgerSeries()->monthlyIncomeAndExpenses(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($series)->toHaveCount(3)
        ->and(monthIn($series, '2026-02')['income_centavos'])->toBe(0);
});

it('reports revenue positive on a credit-normal account', function () {
    seriesEntry('2026-08-10', $this->receivable, $this->tuition, 500_000);

    expect(monthIn(ledgerSeries()->monthlyIncomeAndExpenses(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    ), '2026-08')['income_centavos'])->toBe(500_000);
});

it('reports spending positive on a debit-normal account', function () {
    seriesEntry('2026-08-20', $this->salaries, $this->cash, 200_000);

    expect(monthIn(ledgerSeries()->monthlyIncomeAndExpenses(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    ), '2026-08')['expenses_centavos'])->toBe(200_000);
});

it('ignores entries that never reached the ledger', function () {
    seriesEntry('2026-08-10', $this->receivable, $this->tuition, 500_000, [
        'status' => JournalEntry::STATUS_DRAFT,
    ]);
    seriesEntry('2026-08-11', $this->receivable, $this->tuition, 400_000, [
        'status' => JournalEntry::STATUS_VOIDED,
    ]);

    expect(monthIn(ledgerSeries()->monthlyIncomeAndExpenses(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    ), '2026-08')['income_centavos'])->toBe(0);
});

it('ranges on the entry date, not on when it was posted', function () {
    seriesEntry('2026-08-28', $this->receivable, $this->tuition, 250_000, [
        'posted_at' => CarbonImmutable::parse('2026-09-15'),
    ]);

    $series = ledgerSeries()->monthlyIncomeAndExpenses(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-09-30'),
    );

    expect(monthIn($series, '2026-08')['income_centavos'])->toBe(250_000)
        ->and(monthIn($series, '2026-09')['income_centavos'])->toBe(0);
});

it('includes both ends of the range', function () {
    // The DayBoundary regression, on a series this time: the last day of the
    // last month is exactly where it bites.
    seriesEntry('2026-08-01', $this->receivable, $this->tuition, 100_000);
    seriesEntry('2026-08-31', $this->receivable, $this->tuition, 120_000);

    expect(monthIn(ledgerSeries()->monthlyIncomeAndExpenses(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    ), '2026-08')['income_centavos'])->toBe(220_000);
});

it('leaves balance-sheet accounts out of the series', function () {
    // Cash and receivables move on every entry; charting them beside revenue
    // would double-count the same transaction as both income and an asset.
    seriesEntry('2026-08-10', $this->receivable, $this->tuition, 500_000);

    $august = monthIn(ledgerSeries()->monthlyIncomeAndExpenses(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    ), '2026-08');

    expect($august['income_centavos'] + $august['expenses_centavos'])->toBe(500_000);
});

it('never charts another school\'s ledger', function () {
    $other = School::factory()->create();
    $theirIncome = ChartOfAccount::factory()->income()->create([
        'school_id' => $other->getKey(),
        'code' => '8100',
    ]);
    $theirReceivable = ChartOfAccount::factory()->asset()->create([
        'school_id' => $other->getKey(),
        'code' => '8200',
    ]);

    $entry = JournalEntry::factory()->create([
        'school_id' => $other->getKey(),
        'date' => CarbonImmutable::parse('2026-08-10'),
        'status' => JournalEntry::STATUS_POSTED,
        'total_debit_centavos' => 9_999_900,
        'total_credit_centavos' => 9_999_900,
    ]);
    JournalEntryLine::factory()->create([
        'school_id' => $other->getKey(),
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $theirReceivable->getKey(),
        'debit_centavos' => 9_999_900,
        'credit_centavos' => 0,
    ]);
    JournalEntryLine::factory()->create([
        'school_id' => $other->getKey(),
        'journal_entry_id' => $entry->getKey(),
        'account_id' => $theirIncome->getKey(),
        'debit_centavos' => 0,
        'credit_centavos' => 9_999_900,
        'line_number' => 2,
    ]);

    seriesEntry('2026-08-15', $this->receivable, $this->tuition, 100_000);

    expect(monthIn(ledgerSeries()->monthlyIncomeAndExpenses(
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    ), '2026-08')['income_centavos'])->toBe(100_000);
});
