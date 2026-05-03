<?php

declare(strict_types=1);

use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
use App\ValueObjects\PayPeriodInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Pins PayrollAdjustment::scopeForPeriod inclusion semantics.
 *
 * The contract is: an adjustment whose `applies_on` falls in
 * `[period.start(), period.end()]` (BOTH ends inclusive) belongs to the
 * period. One day off either side is excluded. This matches PH practice:
 * a "May 15" adjustment is meant for the May 1–15 first-half cutoff, not
 * the May 16–31 cutoff.
 */

function fixtureAdjustment(string $appliesOn): PayrollAdjustment
{
    return PayrollAdjustment::factory()
        ->for(EmployeeProfile::factory())
        ->create(['applies_on' => $appliesOn]);
}

it('includes an adjustment dated on the period start day', function (): void {
    $row = fixtureAdjustment('2026-05-01');
    $period = PayPeriodInput::semiMonthlyFirst(2026, 5); // 2026-05-01..2026-05-15

    $hits = PayrollAdjustment::query()->forPeriod($period)->pluck('id');

    expect($hits)->toContain($row->id);
});

it('includes an adjustment dated on the period end day', function (): void {
    $row = fixtureAdjustment('2026-05-15');
    $period = PayPeriodInput::semiMonthlyFirst(2026, 5);

    $hits = PayrollAdjustment::query()->forPeriod($period)->pluck('id');

    expect($hits)->toContain($row->id);
});

it('includes an adjustment dated mid-period', function (): void {
    $row = fixtureAdjustment('2026-05-08');
    $period = PayPeriodInput::semiMonthlyFirst(2026, 5);

    $hits = PayrollAdjustment::query()->forPeriod($period)->pluck('id');

    expect($hits)->toContain($row->id);
});

it('excludes an adjustment dated one day before the period start', function (): void {
    $row = fixtureAdjustment('2026-04-30');
    $period = PayPeriodInput::semiMonthlyFirst(2026, 5);

    $hits = PayrollAdjustment::query()->forPeriod($period)->pluck('id');

    expect($hits)->not->toContain($row->id);
});

it('excludes an adjustment dated one day after the period end', function (): void {
    $row = fixtureAdjustment('2026-05-16');
    $period = PayPeriodInput::semiMonthlyFirst(2026, 5);

    $hits = PayrollAdjustment::query()->forPeriod($period)->pluck('id');

    expect($hits)->not->toContain($row->id);
});

it('includes adjustments dated on both ends of a full monthly period', function (): void {
    $rowStart = fixtureAdjustment('2026-05-01');
    $rowEnd = fixtureAdjustment('2026-05-31');
    $period = PayPeriodInput::monthly(2026, 5);

    $hits = PayrollAdjustment::query()->forPeriod($period)->pluck('id')->all();

    expect($hits)->toContain($rowStart->id)
        ->and($hits)->toContain($rowEnd->id);
});

it('excludes adjustments from adjacent months on a full-month period', function (): void {
    $april = fixtureAdjustment('2026-04-30');
    $june = fixtureAdjustment('2026-06-01');
    $period = PayPeriodInput::monthly(2026, 5);

    $hits = PayrollAdjustment::query()->forPeriod($period)->pluck('id');

    expect($hits)->not->toContain($april->id)
        ->and($hits)->not->toContain($june->id);
});
