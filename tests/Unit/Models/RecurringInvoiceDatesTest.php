<?php

declare(strict_types=1);

use App\Models\Pas\RecurringInvoice;
use Carbon\CarbonImmutable;
use Tests\TestCase;

// TestCase only so Eloquent can resolve a connection for its date casts.
// RefreshDatabase is deliberately absent: nothing here is saved, and every
// date asserted is the model's own arithmetic.
uses(TestCase::class);

/**
 * The cadence arithmetic.
 *
 * Pure date maths, so a unit test with no database. Two of these pin traps
 * that a reasonable implementation walks straight into: `Carbon::create(2027,
 * 2, 31)` silently overflows into March rather than throwing, and iterating
 * with `addMonth()` lets a clamped day stick — 31 Jan → 28 Feb → 28 *Mar*.
 * Both would misbill a real family.
 */
function schedule(array $attributes = []): RecurringInvoice
{
    return (new RecurringInvoice)->forceFill(array_merge([
        'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
        'day_of_month' => 1,
        'starts_on' => CarbonImmutable::parse('2026-08-01'),
    ], $attributes));
}

it('bills the same day each month', function () {
    $s = schedule(['day_of_month' => 15, 'starts_on' => CarbonImmutable::parse('2026-08-15')]);

    expect($s->issueDateForPeriod(0)->toDateString())->toBe('2026-08-15')
        ->and($s->issueDateForPeriod(1)->toDateString())->toBe('2026-09-15')
        ->and($s->issueDateForPeriod(2)->toDateString())->toBe('2026-10-15');
});

it('never bills a month that had already passed when the schedule was made', function () {
    // Created on the 30th to bill on the 1st. That means next month's 1st —
    // not a backdated invoice for a month gone by before the schedule existed.
    $s = schedule([
        'day_of_month' => 1,
        'starts_on' => CarbonImmutable::parse('2026-08-30'),
    ]);

    expect($s->issueDateForPeriod(0)->toDateString())->toBe('2026-09-01')
        ->and($s->issueDateForPeriod(1)->toDateString())->toBe('2026-10-01');
});

it('bills the starting month when the chosen day has not passed yet', function () {
    $s = schedule([
        'day_of_month' => 15,
        'starts_on' => CarbonImmutable::parse('2026-08-01'),
    ]);

    expect($s->issueDateForPeriod(0)->toDateString())->toBe('2026-08-15');
});

it('bills the starting day itself when the schedule starts on it', function () {
    $s = schedule([
        'day_of_month' => 15,
        'starts_on' => CarbonImmutable::parse('2026-08-15'),
    ]);

    expect($s->issueDateForPeriod(0)->toDateString())->toBe('2026-08-15');
});

it('skips a whole quarter when a quarterly schedule starts after its day', function () {
    $s = schedule([
        'frequency' => RecurringInvoice::FREQUENCY_QUARTERLY,
        'day_of_month' => 5,
        'starts_on' => CarbonImmutable::parse('2026-08-20'),
    ]);

    expect($s->issueDateForPeriod(0)->toDateString())->toBe('2026-11-05');
});

it('clamps a 31st to the last day of a short month', function () {
    $s = schedule(['day_of_month' => 31, 'starts_on' => CarbonImmutable::parse('2027-01-31')]);

    // February 2027 has 28 days. Building this with Carbon::create(2027, 2, 31)
    // would overflow to 3 March and bill the wrong month entirely.
    expect($s->issueDateForPeriod(1)->toDateString())->toBe('2027-02-28');
});

it('returns to the 31st after a short month, rather than sticking', function () {
    // The addMonth() trap: 31 Jan -> 28 Feb -> 28 Mar. March has 31 days, so
    // a schedule set to the 31st must bill the 31st.
    $s = schedule(['day_of_month' => 31, 'starts_on' => CarbonImmutable::parse('2027-01-31')]);

    expect($s->issueDateForPeriod(2)->toDateString())->toBe('2027-03-31')
        ->and($s->issueDateForPeriod(3)->toDateString())->toBe('2027-04-30')
        ->and($s->issueDateForPeriod(4)->toDateString())->toBe('2027-05-31');
});

it('clamps to 29 February in a leap year', function () {
    $s = schedule(['day_of_month' => 30, 'starts_on' => CarbonImmutable::parse('2028-01-30')]);

    expect($s->issueDateForPeriod(1)->toDateString())->toBe('2028-02-29');
});

it('advances three months at a time when quarterly', function () {
    $s = schedule([
        'frequency' => RecurringInvoice::FREQUENCY_QUARTERLY,
        'day_of_month' => 5,
        'starts_on' => CarbonImmutable::parse('2026-08-05'),
    ]);

    expect($s->issueDateForPeriod(1)->toDateString())->toBe('2026-11-05')
        ->and($s->issueDateForPeriod(2)->toDateString())->toBe('2027-02-05');
});

it('advances a year at a time when annual', function () {
    $s = schedule([
        'frequency' => RecurringInvoice::FREQUENCY_ANNUALLY,
        'day_of_month' => 1,
        'starts_on' => CarbonImmutable::parse('2026-06-01'),
    ]);

    expect($s->issueDateForPeriod(1)->toDateString())->toBe('2027-06-01');
});

it('keys monthly and quarterly periods by year and month', function () {
    $s = schedule();

    expect($s->periodKeyFor(CarbonImmutable::parse('2026-09-01')))->toBe('2026-09');
});

it('keys an annual period by year alone', function () {
    // Two annual invoices in one calendar year would be a double bill, so the
    // key must not include the month.
    $s = schedule(['frequency' => RecurringInvoice::FREQUENCY_ANNUALLY]);

    expect($s->periodKeyFor(CarbonImmutable::parse('2026-09-01')))->toBe('2026');
});

it('gives every period of a run a distinct key', function () {
    $s = schedule(['day_of_month' => 31, 'starts_on' => CarbonImmutable::parse('2027-01-31')]);

    $keys = array_map(
        fn (int $i): string => $s->periodKeyFor($s->issueDateForPeriod($i)),
        range(0, 11),
    );

    expect($keys)->toBe(array_unique($keys))
        ->and($keys[0])->toBe('2027-01')
        ->and($keys[11])->toBe('2027-12');
});
