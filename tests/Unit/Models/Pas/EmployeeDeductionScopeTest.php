<?php

declare(strict_types=1);

use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeProfile;
use App\ValueObjects\PayPeriodInput;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Pins the date-bounded `scopeActiveOn` and the schedule-matching
 * `scopeForSchedule` matrix on EmployeeDeduction. The same matrix is
 * delegated by EmployeeAllowance via the static helper, so this file is
 * the canonical fixture for both.
 */

/**
 * @param  array<string, mixed>  $overrides
 */
function fixtureDeduction(array $overrides = []): EmployeeDeduction
{
    return EmployeeDeduction::factory()
        ->for(EmployeeProfile::factory())
        ->for(DeductionType::factory())
        ->create($overrides);
}

it('includes a row whose effective_from is on the queried date (inclusive lower bound)', function (): void {
    $row = fixtureDeduction([
        'effective_from' => '2026-05-15',
        'effective_to' => null,
    ]);

    $hits = EmployeeDeduction::query()
        ->activeOn(CarbonImmutable::parse('2026-05-15'))
        ->pluck('id');

    expect($hits)->toContain($row->id);
});

it('includes a row whose effective_from is in the past and effective_to is null', function (): void {
    $row = fixtureDeduction([
        'effective_from' => '2025-01-01',
        'effective_to' => null,
    ]);

    $hits = EmployeeDeduction::query()
        ->activeOn(CarbonImmutable::parse('2026-05-15'))
        ->pluck('id');

    expect($hits)->toContain($row->id);
});

it('excludes a row on the day equal to effective_to (exclusive upper bound)', function (): void {
    $row = fixtureDeduction([
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-05-31',
    ]);

    $hits = EmployeeDeduction::query()
        ->activeOn(CarbonImmutable::parse('2026-05-31'))
        ->pluck('id');

    expect($hits)->not->toContain($row->id);
});

it('includes a row on the day before effective_to', function (): void {
    $row = fixtureDeduction([
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-05-31',
    ]);

    $hits = EmployeeDeduction::query()
        ->activeOn(CarbonImmutable::parse('2026-05-30'))
        ->pluck('id');

    expect($hits)->toContain($row->id);
});

it('excludes a row whose effective_from is in the future', function (): void {
    $row = fixtureDeduction([
        'effective_from' => '2026-06-01',
        'effective_to' => null,
    ]);

    $hits = EmployeeDeduction::query()
        ->activeOn(CarbonImmutable::parse('2026-05-31'))
        ->pluck('id');

    expect($hits)->not->toContain($row->id);
});

it('matches every_run on a monthly run', function (): void {
    $row = fixtureDeduction(['schedule' => EmployeeDeduction::SCHEDULE_EVERY_RUN]);
    $period = PayPeriodInput::monthly(2026, 5);

    $hits = EmployeeDeduction::query()->forSchedule($period)->pluck('id');

    expect($hits)->toContain($row->id);
});

it('matches every_run on the semi-monthly first half', function (): void {
    $row = fixtureDeduction(['schedule' => EmployeeDeduction::SCHEDULE_EVERY_RUN]);
    $period = PayPeriodInput::semiMonthlyFirst(2026, 5);

    $hits = EmployeeDeduction::query()->forSchedule($period)->pluck('id');

    expect($hits)->toContain($row->id);
});

it('matches every_run on the semi-monthly second half', function (): void {
    $row = fixtureDeduction(['schedule' => EmployeeDeduction::SCHEDULE_EVERY_RUN]);
    $period = PayPeriodInput::semiMonthlySecond(2026, 5);

    $hits = EmployeeDeduction::query()->forSchedule($period)->pluck('id');

    expect($hits)->toContain($row->id);
});

it('matches first_half only on the semi-monthly first half', function (): void {
    $row = fixtureDeduction(['schedule' => EmployeeDeduction::SCHEDULE_FIRST_HALF]);

    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::semiMonthlyFirst(2026, 5))->pluck('id'))
        ->toContain($row->id);
    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::semiMonthlySecond(2026, 5))->pluck('id'))
        ->not->toContain($row->id);
    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::monthly(2026, 5))->pluck('id'))
        ->not->toContain($row->id);
});

it('matches second_half only on the semi-monthly second half', function (): void {
    $row = fixtureDeduction(['schedule' => EmployeeDeduction::SCHEDULE_SECOND_HALF]);

    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::semiMonthlyFirst(2026, 5))->pluck('id'))
        ->not->toContain($row->id);
    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::semiMonthlySecond(2026, 5))->pluck('id'))
        ->toContain($row->id);
    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::monthly(2026, 5))->pluck('id'))
        ->not->toContain($row->id);
});

it('matches monthly_first on a monthly run and on the semi-monthly first half only', function (): void {
    $row = fixtureDeduction(['schedule' => EmployeeDeduction::SCHEDULE_MONTHLY_FIRST]);

    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::monthly(2026, 5))->pluck('id'))
        ->toContain($row->id);
    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::semiMonthlyFirst(2026, 5))->pluck('id'))
        ->toContain($row->id);
    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::semiMonthlySecond(2026, 5))->pluck('id'))
        ->not->toContain($row->id);
});

it('matches monthly_last on a monthly run and on the semi-monthly second half only', function (): void {
    $row = fixtureDeduction(['schedule' => EmployeeDeduction::SCHEDULE_MONTHLY_LAST]);

    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::monthly(2026, 5))->pluck('id'))
        ->toContain($row->id);
    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::semiMonthlyFirst(2026, 5))->pluck('id'))
        ->not->toContain($row->id);
    expect(EmployeeDeduction::query()->forSchedule(PayPeriodInput::semiMonthlySecond(2026, 5))->pluck('id'))
        ->toContain($row->id);
});
