<?php

declare(strict_types=1);

use App\Services\Accounting\Reports\StatementColumn;
use App\Services\Accounting\Reports\StatementColumnResolver;
use App\Services\Accounting\Reports\StatementColumnSet;
use App\Support\Centavos;
use Carbon\CarbonImmutable;

/*
 * Which dates each comparison column covers (Phase 5 Slice 8b).
 *
 * Every case here is a calendar edge, because that is the only way this goes
 * wrong: months of unequal length, a leap day, a range that starts mid-month.
 * A comparison that is off by three days still prints plausible figures, so
 * nothing downstream would notice.
 */

/**
 * @return array{0: string, 1: string} the column's from and to dates
 */
function statementColumns(StatementColumn $column): array
{
    return [$column->from->toDateString(), $column->to->toDateString()];
}

function resolveStatementColumns(
    string $from,
    string $to,
    string $mode,
    ?string $compareFrom = null,
    ?string $compareTo = null,
    ?string $booksOpenedOn = null,
): StatementColumnSet {
    $parse = static fn (?string $date): ?CarbonImmutable => $date === null
        ? null
        : CarbonImmutable::parse($date);

    return (new StatementColumnResolver)->resolve(
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to),
        $mode,
        $parse($compareFrom),
        $parse($compareTo),
        $parse($booksOpenedOn),
    );
}

/* ── No comparison ──────────────────────────────────────────────────── */

it('reports one column when nothing is compared', function () {
    $set = resolveStatementColumns('2026-07-01', '2026-07-31', 'none');

    expect($set->mode)->toBe('none')
        ->and($set->columns)->toHaveCount(1)
        ->and($set->hasVariance)->toBeFalse()
        ->and($set->range->label)->toBe('Jul 2026');
});

it('treats an unknown mode as no comparison', function () {
    expect(resolveStatementColumns('2026-07-01', '2026-07-31', 'sideways')->mode)->toBe('none');
});

it('swaps an inverted range rather than refusing it', function () {
    $set = resolveStatementColumns('2026-07-31', '2026-07-01', 'none');

    expect(statementColumns($set->range))->toBe(['2026-07-01', '2026-07-31']);
});

/* ── Previous period ────────────────────────────────────────────────── */

it('compares whole months against the whole months before', function (string $from, string $to, array $expected) {
    $set = resolveStatementColumns($from, $to, 'previous_period');

    expect(statementColumns($set->comparison()))->toBe($expected)
        ->and($set->hasVariance)->toBeTrue();
})->with([
    // The case a day count gets wrong: 28 days back from 31 Jan is 4 Jan.
    'February against all of January' => ['2026-02-01', '2026-02-28', ['2026-01-01', '2026-01-31']],
    'March against all of February' => ['2026-03-01', '2026-03-31', ['2026-02-01', '2026-02-28']],
    'a quarter against the quarter before' => ['2026-04-01', '2026-06-30', ['2026-01-01', '2026-03-31']],
    'a year against the year before' => ['2026-01-01', '2026-12-31', ['2025-01-01', '2025-12-31']],
    'across a year boundary' => ['2026-01-01', '2026-01-31', ['2025-12-01', '2025-12-31']],
]);

it('compares a part-month range against the same number of days before', function () {
    // 10–19 July is ten days; the ten before are 30 June to 9 July.
    $set = resolveStatementColumns('2026-07-10', '2026-07-19', 'previous_period');

    expect(statementColumns($set->comparison()))->toBe(['2026-06-30', '2026-07-09']);
});

/* ── Same period last year ──────────────────────────────────────────── */

it('compares against the same dates a year earlier', function (string $from, string $to, array $expected) {
    $set = resolveStatementColumns($from, $to, 'previous_year');

    expect(statementColumns($set->comparison()))->toBe($expected);
})->with([
    'an ordinary month' => ['2026-07-01', '2026-07-31', ['2025-07-01', '2025-07-31']],
    'a part month' => ['2026-07-10', '2026-07-19', ['2025-07-10', '2025-07-19']],
    // Leap February compared with an ordinary one: still the whole month.
    'leap February back to an ordinary one' => ['2028-02-01', '2028-02-29', ['2027-02-01', '2027-02-28']],
    // And the other way: 28 Feb 2029 is a month end, so is 29 Feb 2028.
    'ordinary February back to a leap one' => ['2029-02-01', '2029-02-28', ['2028-02-01', '2028-02-29']],
    'a range starting on a leap day' => ['2028-02-29', '2028-03-15', ['2027-02-28', '2027-03-15']],
]);

/* ── Month by month ─────────────────────────────────────────────────── */

it('prints a column for each month and then the total', function () {
    $set = resolveStatementColumns('2026-01-01', '2026-03-31', 'monthly');

    expect($set->mode)->toBe('monthly')
        ->and(array_map(fn (StatementColumn $column): string => $column->label, $set->columns))
        ->toBe(['Jan 2026', 'Feb 2026', 'Mar 2026', 'Total'])
        ->and($set->hasVariance)->toBeFalse()
        ->and(statementColumns($set->columns[3]))->toBe(['2026-01-01', '2026-03-31']);
});

it('clips the first and last month to the range and labels them by their days', function () {
    $set = resolveStatementColumns('2026-07-15', '2026-09-10', 'monthly');

    expect(array_map(fn (StatementColumn $column): string => $column->label, $set->columns))
        ->toBe(['15–31 Jul 2026', 'Aug 2026', '1–10 Sep 2026', 'Total'])
        ->and(statementColumns($set->columns[0]))->toBe(['2026-07-15', '2026-07-31'])
        ->and(statementColumns($set->columns[2]))->toBe(['2026-09-01', '2026-09-10']);
});

it('prints twelve months', function () {
    $set = resolveStatementColumns('2026-01-01', '2026-12-31', 'monthly');

    expect($set->mode)->toBe('monthly')->and($set->columns)->toHaveCount(13);
});

it('falls back to one column, and says so, past twelve months', function () {
    // Not truncated: a total under twelve visible months that includes a
    // thirteenth is a figure nobody can check.
    $set = resolveStatementColumns('2026-01-01', '2027-01-31', 'monthly');

    expect($set->mode)->toBe('none')
        ->and($set->columns)->toHaveCount(1)
        ->and($set->notices)->toHaveCount(1)
        ->and($set->notices[0])->toContain('12 months');
});

/* ── Custom comparison ──────────────────────────────────────────────── */

it('compares against the range the reader chose', function () {
    $set = resolveStatementColumns('2026-07-01', '2026-07-31', 'custom', '2026-03-01', '2026-03-31');

    expect($set->mode)->toBe('custom')
        ->and(statementColumns($set->comparison()))->toBe(['2026-03-01', '2026-03-31'])
        ->and($set->notices)->toBe([]);
});

it('swaps an inverted comparison range', function () {
    $set = resolveStatementColumns('2026-07-01', '2026-07-31', 'custom', '2026-03-31', '2026-03-01');

    expect(statementColumns($set->comparison()))->toBe(['2026-03-01', '2026-03-31']);
});

it('falls back to no comparison when a comparison date is missing', function (?string $from, ?string $to) {
    $set = resolveStatementColumns('2026-07-01', '2026-07-31', 'custom', $from, $to);

    expect($set->mode)->toBe('none')
        ->and($set->columns)->toHaveCount(1)
        ->and($set->notices)->toHaveCount(1);
})->with([
    'no end' => ['2026-03-01', null],
    'no start' => [null, '2026-03-31'],
    'neither' => [null, null],
]);

it('allows an overlapping comparison but says entries are counted twice', function () {
    $set = resolveStatementColumns('2026-07-01', '2026-07-31', 'custom', '2026-07-15', '2026-08-15');

    expect($set->mode)->toBe('custom')
        ->and($set->notices)->toHaveCount(1)
        ->and($set->notices[0])->toContain('overlaps');
});

it('says so when the comparison ends before the books were opened', function () {
    $set = resolveStatementColumns(
        '2026-07-01', '2026-07-31', 'previous_year',
        booksOpenedOn: '2026-01-01',
    );

    expect($set->notices)->toHaveCount(1)
        ->and($set->notices[0])->toContain('1 Jan 2026');
});

/* ── Variance, without a float ──────────────────────────────────────── */

it('states a change in basis points of the comparison', function (int $current, int $comparison, ?int $expected) {
    expect(Centavos::changeBasisPoints($current, $comparison))->toBe($expected);
})->with([
    'up an eighth' => [112_500, 100_000, 1250],
    'down a quarter' => [75_000, 100_000, -2500],
    'unchanged' => [100_000, 100_000, 0],
    // One third is 3333.33… basis points; two thirds is 6666.66…
    'rounds down below the half' => [400, 300, 3333],
    'rounds up above the half' => [500, 300, 6667],
    'rounds the half up' => [100_005, 100_000, 1],
    'from nothing has no percentage' => [50_000, 0, null],
    // A loss of 100 that became a loss of 50 improved by half.
    'a smaller loss is an increase' => [-5_000, -10_000, 5000],
    'a profit turned to a loss' => [-10_000, 10_000, -20000],
]);

it('prints centavos as pesos without dividing', function (int $centavos, string $expected) {
    expect(Centavos::decimal($centavos))->toBe($expected);
})->with([
    [0, '0.00'],
    [5, '0.05'],
    [123_456, '1234.56'],
    [-123_456, '-1234.56'],
    [-5, '-0.05'],
]);

it('prints basis points as a percentage', function () {
    expect(Centavos::percentFromBasisPoints(1250))->toBe('12.50%')
        ->and(Centavos::percentFromBasisPoints(-2500))->toBe('-25.00%')
        ->and(Centavos::percentFromBasisPoints(null))->toBe('');
});
