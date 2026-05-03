<?php

declare(strict_types=1);

use App\Models\Pas\Allowance;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeProfile;
use App\ValueObjects\PayPeriodInput;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * EmployeeAllowance scopes mirror EmployeeDeduction's: `scopeActiveOn`
 * uses `[effective_from, effective_to)`, and `scopeForSchedule` delegates
 * to the static helper on EmployeeDeduction so the matrix lives in one
 * place. This test file is intentionally smaller than the deduction file —
 * the matrix itself is exhaustively pinned there. Here we only confirm
 * the wiring: a representative pass and fail for both scopes against
 * EmployeeAllowance specifically.
 */

/**
 * @param  array<string, mixed>  $overrides
 */
function fixtureAllowance(array $overrides = []): EmployeeAllowance
{
    return EmployeeAllowance::factory()
        ->for(EmployeeProfile::factory())
        ->for(Allowance::factory())
        ->create($overrides);
}

it('includes an allowance whose effective_from is on the queried date', function (): void {
    $row = fixtureAllowance([
        'effective_from' => '2026-05-15',
        'effective_to' => null,
    ]);

    $hits = EmployeeAllowance::query()
        ->activeOn(CarbonImmutable::parse('2026-05-15'))
        ->pluck('id');

    expect($hits)->toContain($row->id);
});

it('excludes an allowance on the day equal to effective_to (exclusive)', function (): void {
    $row = fixtureAllowance([
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-05-31',
    ]);

    $hits = EmployeeAllowance::query()
        ->activeOn(CarbonImmutable::parse('2026-05-31'))
        ->pluck('id');

    expect($hits)->not->toContain($row->id);
});

it('matches every_run on every period frequency', function (): void {
    $row = fixtureAllowance(['schedule' => EmployeeDeduction::SCHEDULE_EVERY_RUN]);

    expect(EmployeeAllowance::query()->forSchedule(PayPeriodInput::monthly(2026, 5))->pluck('id'))
        ->toContain($row->id);
    expect(EmployeeAllowance::query()->forSchedule(PayPeriodInput::semiMonthlyFirst(2026, 5))->pluck('id'))
        ->toContain($row->id);
    expect(EmployeeAllowance::query()->forSchedule(PayPeriodInput::semiMonthlySecond(2026, 5))->pluck('id'))
        ->toContain($row->id);
});

it('matches first_half allowance only on the semi-monthly first half', function (): void {
    $row = fixtureAllowance(['schedule' => EmployeeDeduction::SCHEDULE_FIRST_HALF]);

    expect(EmployeeAllowance::query()->forSchedule(PayPeriodInput::semiMonthlyFirst(2026, 5))->pluck('id'))
        ->toContain($row->id);
    expect(EmployeeAllowance::query()->forSchedule(PayPeriodInput::semiMonthlySecond(2026, 5))->pluck('id'))
        ->not->toContain($row->id);
    expect(EmployeeAllowance::query()->forSchedule(PayPeriodInput::monthly(2026, 5))->pluck('id'))
        ->not->toContain($row->id);
});

it('matches monthly_last allowance on monthly run and semi-monthly second half', function (): void {
    $row = fixtureAllowance(['schedule' => EmployeeDeduction::SCHEDULE_MONTHLY_LAST]);

    expect(EmployeeAllowance::query()->forSchedule(PayPeriodInput::monthly(2026, 5))->pluck('id'))
        ->toContain($row->id);
    expect(EmployeeAllowance::query()->forSchedule(PayPeriodInput::semiMonthlySecond(2026, 5))->pluck('id'))
        ->toContain($row->id);
    expect(EmployeeAllowance::query()->forSchedule(PayPeriodInput::semiMonthlyFirst(2026, 5))->pluck('id'))
        ->not->toContain($row->id);
});
