<?php

declare(strict_types=1);

use App\ValueObjects\PayPeriodInput;
use Carbon\CarbonImmutable;

// ---------------------------------------------------------------------
// monthly() — date ranges and days-in-period
// ---------------------------------------------------------------------

it('builds a 31-day monthly period for May 2026', function (): void {
    $period = PayPeriodInput::monthly(2026, 5);

    expect($period->start()->toDateString())->toBe('2026-05-01')
        ->and($period->end()->toDateString())->toBe('2026-05-31')
        ->and($period->frequency())->toBe(PayPeriodInput::FREQUENCY_MONTHLY)
        ->and($period->daysInPeriod())->toBe(31);
});

it('builds a 28-day monthly period for non-leap February', function (): void {
    $period = PayPeriodInput::monthly(2026, 2);

    expect($period->start()->toDateString())->toBe('2026-02-01')
        ->and($period->end()->toDateString())->toBe('2026-02-28')
        ->and($period->daysInPeriod())->toBe(28);
});

it('builds a 29-day monthly period for leap February', function (): void {
    $period = PayPeriodInput::monthly(2024, 2);

    expect($period->end()->toDateString())->toBe('2024-02-29')
        ->and($period->daysInPeriod())->toBe(29);
});

// ---------------------------------------------------------------------
// semiMonthlyFirst() — always 1..15 inclusive
// ---------------------------------------------------------------------

it('builds the first half of May 2026 (1..15)', function (): void {
    $period = PayPeriodInput::semiMonthlyFirst(2026, 5);

    expect($period->start()->toDateString())->toBe('2026-05-01')
        ->and($period->end()->toDateString())->toBe('2026-05-15')
        ->and($period->frequency())->toBe(PayPeriodInput::FREQUENCY_SEMI_MONTHLY)
        ->and($period->daysInPeriod())->toBe(15);
});

// ---------------------------------------------------------------------
// semiMonthlySecond() — 16..end-of-month, varies with month length
// ---------------------------------------------------------------------

it('builds the second half of May 2026 (16..31)', function (): void {
    $period = PayPeriodInput::semiMonthlySecond(2026, 5);

    expect($period->start()->toDateString())->toBe('2026-05-16')
        ->and($period->end()->toDateString())->toBe('2026-05-31')
        ->and($period->daysInPeriod())->toBe(16);
});

it('builds a 13-day second half for non-leap February', function (): void {
    $period = PayPeriodInput::semiMonthlySecond(2026, 2);

    expect($period->start()->toDateString())->toBe('2026-02-16')
        ->and($period->end()->toDateString())->toBe('2026-02-28')
        ->and($period->daysInPeriod())->toBe(13);
});

it('builds a 14-day second half for leap February', function (): void {
    $period = PayPeriodInput::semiMonthlySecond(2024, 2);

    expect($period->start()->toDateString())->toBe('2024-02-16')
        ->and($period->end()->toDateString())->toBe('2024-02-29')
        ->and($period->daysInPeriod())->toBe(14);
});

// ---------------------------------------------------------------------
// Validation — month bounds
// ---------------------------------------------------------------------

it('rejects month 0 in monthly()', function (): void {
    PayPeriodInput::monthly(2026, 0);
})->throws(InvalidArgumentException::class);

it('rejects month 13 in monthly()', function (): void {
    PayPeriodInput::monthly(2026, 13);
})->throws(InvalidArgumentException::class);

it('rejects month 0 in semiMonthlyFirst()', function (): void {
    PayPeriodInput::semiMonthlyFirst(2026, 0);
})->throws(InvalidArgumentException::class);

it('rejects month 13 in semiMonthlySecond()', function (): void {
    PayPeriodInput::semiMonthlySecond(2026, 13);
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------
// custom() — validation matrix
// ---------------------------------------------------------------------

it('rejects custom() with end before start', function (): void {
    PayPeriodInput::custom(
        CarbonImmutable::create(2026, 5, 31),
        CarbonImmutable::create(2026, 5, 1),
        PayPeriodInput::FREQUENCY_MONTHLY,
    );
})->throws(InvalidArgumentException::class, 'on or after');

it('rejects custom() with an unknown frequency', function (): void {
    PayPeriodInput::custom(
        CarbonImmutable::create(2026, 5, 1),
        CarbonImmutable::create(2026, 5, 31),
        'weekly',
    );
})->throws(InvalidArgumentException::class, 'unknown frequency');

it('rejects custom() monthly with only 5 days', function (): void {
    PayPeriodInput::custom(
        CarbonImmutable::create(2026, 5, 1),
        CarbonImmutable::create(2026, 5, 5),
        PayPeriodInput::FREQUENCY_MONTHLY,
    );
})->throws(InvalidArgumentException::class, 'monthly period must span 28..31 days');

it('rejects custom() semi_monthly with 20 days', function (): void {
    PayPeriodInput::custom(
        CarbonImmutable::create(2026, 5, 1),
        CarbonImmutable::create(2026, 5, 20),
        PayPeriodInput::FREQUENCY_SEMI_MONTHLY,
    );
})->throws(InvalidArgumentException::class, 'semi_monthly period must span 13..16 days');

it('accepts custom() monthly with exactly 28 days (Feb non-leap)', function (): void {
    $period = PayPeriodInput::custom(
        CarbonImmutable::create(2026, 2, 1),
        CarbonImmutable::create(2026, 2, 28),
        PayPeriodInput::FREQUENCY_MONTHLY,
    );

    expect($period->daysInPeriod())->toBe(28)
        ->and($period->isMonthly())->toBeTrue();
});

it('accepts custom() semi_monthly with exactly 13 days', function (): void {
    $period = PayPeriodInput::custom(
        CarbonImmutable::create(2026, 2, 16),
        CarbonImmutable::create(2026, 2, 28),
        PayPeriodInput::FREQUENCY_SEMI_MONTHLY,
    );

    expect($period->daysInPeriod())->toBe(13)
        ->and($period->isSemiMonthly())->toBeTrue();
});

// ---------------------------------------------------------------------
// equals()
// ---------------------------------------------------------------------

it('treats two monthly(2026,5) instances as equal', function (): void {
    expect(PayPeriodInput::monthly(2026, 5)->equals(PayPeriodInput::monthly(2026, 5)))
        ->toBeTrue();
});

it('treats monthly(2026,5) and monthly(2026,6) as not equal', function (): void {
    expect(PayPeriodInput::monthly(2026, 5)->equals(PayPeriodInput::monthly(2026, 6)))
        ->toBeFalse();
});

it('treats overlapping monthly and semi_monthly periods as not equal', function (): void {
    // Both share start=2026-05-01 but differ in end and frequency.
    expect(PayPeriodInput::monthly(2026, 5)->equals(PayPeriodInput::semiMonthlyFirst(2026, 5)))
        ->toBeFalse();
});

// ---------------------------------------------------------------------
// __toString()
// ---------------------------------------------------------------------

it('renders an unambiguous debug string', function (): void {
    expect((string) PayPeriodInput::monthly(2026, 5))
        ->toBe('2026-05-01..2026-05-31 (monthly)')
        ->and((string) PayPeriodInput::semiMonthlySecond(2024, 2))
        ->toBe('2024-02-16..2024-02-29 (semi_monthly)');
});

// ---------------------------------------------------------------------
// isMonthly() / isSemiMonthly() helpers
// ---------------------------------------------------------------------

it('reports isMonthly() true and isSemiMonthly() false for a monthly period', function (): void {
    $period = PayPeriodInput::monthly(2026, 5);

    expect($period->isMonthly())->toBeTrue()
        ->and($period->isSemiMonthly())->toBeFalse();
});

it('reports isSemiMonthly() true and isMonthly() false for a semi_monthly period', function (): void {
    $period = PayPeriodInput::semiMonthlyFirst(2026, 5);

    expect($period->isSemiMonthly())->toBeTrue()
        ->and($period->isMonthly())->toBeFalse();
});
