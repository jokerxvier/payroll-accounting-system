<?php

declare(strict_types=1);

use App\Actions\Payroll\ComputeBasicPay;
use App\Models\Pas\EmployeeProfile;
use App\ValueObjects\PayPeriodInput;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Pure-domain tests for ComputeBasicPay. No DB hit — every profile is
 * built via `EmployeeProfile::factory()->make([...])` so RefreshDatabase
 * is unnecessary. We still need TestCase to bootstrap the Laravel container
 * (the factory pulls Faker from it). The factory's date_terminated default
 * is null and date_hired is in the past; we override both as needed.
 *
 * Reference month: May 2026 (31 days), monthly basic 30,000.00 PHP =
 * 3,000,000 centavos. Half-month (semi-monthly) is 1,500,000 centavos.
 * Pro-ration is daily over the calendar period.
 */

beforeEach(function (): void {
    $this->action = new ComputeBasicPay;
});

it('returns the full monthly basic for an active employee covering the full month', function (): void {
    $profile = EmployeeProfile::factory()->make([
        'is_active' => true,
        'basic_salary_centavos' => 3_000_000,
        'date_hired' => CarbonImmutable::create(2024, 1, 1),
        'date_terminated' => null,
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    expect(($this->action)($profile, $period)->centavos())->toBe(3_000_000);
});

it('halves the monthly basic for a semi-monthly first-half period', function (): void {
    $profile = EmployeeProfile::factory()->make([
        'is_active' => true,
        'basic_salary_centavos' => 3_000_000,
        'date_hired' => CarbonImmutable::create(2024, 1, 1),
        'date_terminated' => null,
    ]);

    $period = PayPeriodInput::semiMonthlyFirst(2026, 5);

    // 3,000,000 / 2 = 1,500,000.
    expect(($this->action)($profile, $period)->centavos())->toBe(1_500_000);
});

it('returns zero for an inactive employee even with full overlap', function (): void {
    $profile = EmployeeProfile::factory()->make([
        'is_active' => false,
        'basic_salary_centavos' => 3_000_000,
        'date_hired' => CarbonImmutable::create(2024, 1, 1),
        'date_terminated' => null,
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    expect(($this->action)($profile, $period)->centavos())->toBe(0);
});

it('pro-rates an employee hired mid-period', function (): void {
    // Hired 2026-05-15. Period is 2026-05-01..2026-05-31 (31 days).
    // Worked window: 2026-05-15..2026-05-31 = 17 days inclusive.
    // Pro-rated: 3,000,000 * 17 / 31 = 51,000,000 / 31
    //          = 1,645,161.29..., banker's rounding → 1,645,161.
    $profile = EmployeeProfile::factory()->make([
        'is_active' => true,
        'basic_salary_centavos' => 3_000_000,
        'date_hired' => CarbonImmutable::create(2026, 5, 15),
        'date_terminated' => null,
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    expect(($this->action)($profile, $period)->centavos())->toBe(1_645_161);
});

it('pro-rates an employee terminated mid-period', function (): void {
    // Terminated 2026-05-20. Period is 2026-05-01..2026-05-31 (31 days).
    // Worked window: 2026-05-01..2026-05-20 = 20 days inclusive.
    // Pro-rated: 3,000,000 * 20 / 31 = 60,000,000 / 31
    //          = 1,935,483.87..., banker's rounding → 1,935,484.
    $profile = EmployeeProfile::factory()->make([
        'is_active' => true,
        'basic_salary_centavos' => 3_000_000,
        'date_hired' => CarbonImmutable::create(2024, 1, 1),
        'date_terminated' => CarbonImmutable::create(2026, 5, 20),
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    expect(($this->action)($profile, $period)->centavos())->toBe(1_935_484);
});

it('returns zero when the employee was hired after the period ended', function (): void {
    $profile = EmployeeProfile::factory()->make([
        'is_active' => true,
        'basic_salary_centavos' => 3_000_000,
        'date_hired' => CarbonImmutable::create(2026, 6, 1),
        'date_terminated' => null,
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    expect(($this->action)($profile, $period)->centavos())->toBe(0);
});

it('returns zero when the employee was terminated before the period started', function (): void {
    $profile = EmployeeProfile::factory()->make([
        'is_active' => true,
        'basic_salary_centavos' => 3_000_000,
        'date_hired' => CarbonImmutable::create(2024, 1, 1),
        'date_terminated' => CarbonImmutable::create(2026, 4, 30),
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    expect(($this->action)($profile, $period)->centavos())->toBe(0);
});

it('returns zero for a zero-salary active employee', function (): void {
    $profile = EmployeeProfile::factory()->make([
        'is_active' => true,
        'basic_salary_centavos' => 0,
        'date_hired' => CarbonImmutable::create(2024, 1, 1),
        'date_terminated' => null,
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    expect(($this->action)($profile, $period)->centavos())->toBe(0);
});

it('returns the full basic when hired exactly on period start and terminated exactly on period end', function (): void {
    // Both endpoints inclusive: 2026-05-01..2026-05-31 = 31 days, full coverage.
    $profile = EmployeeProfile::factory()->make([
        'is_active' => true,
        'basic_salary_centavos' => 3_000_000,
        'date_hired' => CarbonImmutable::create(2026, 5, 1),
        'date_terminated' => CarbonImmutable::create(2026, 5, 31),
    ]);

    $period = PayPeriodInput::monthly(2026, 5);

    expect(($this->action)($profile, $period)->centavos())->toBe(3_000_000);
});
