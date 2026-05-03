<?php

declare(strict_types=1);

use App\Actions\Payroll\ApplyUnpaidDays;
use App\Models\Pas\EmployeeProfile;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Tests for ApplyUnpaidDays — currently STUBBED.
 *
 * The LMS schema (sm_leave_requests / sm_leave_types / sm_leave_defines /
 * sm_leave_deduction_infos) does not expose a clean "unpaid leave" signal,
 * so the action returns UnpaidDaysReduction::zero() for every input
 * pending a client decision on the LMS leave bridge contract. See the
 * action's PHPDoc for the full rationale and the future-implementation
 * plan.
 *
 * These tests pin the zero-return behaviour so the engine layer can
 * compose the action without a placeholder. When the real implementation
 * lands, this file is updated to test the LMS bridge end-to-end; the
 * engine signature does not change.
 *
 * No DB hit needed — the stub never queries.
 */

beforeEach(function (): void {
    $this->action = new ApplyUnpaidDays;
    $this->profile = EmployeeProfile::factory()->make([
        'is_active' => true,
        'basic_salary_centavos' => 3_000_000,
    ]);
});

it('returns zero reduction for a monthly period with non-zero basic pay', function (): void {
    $result = ($this->action)($this->profile, PayPeriodInput::monthly(2026, 5), Money::fromCentavos(3_000_000));

    expect($result->reduction->centavos())->toBe(0)
        ->and($result->unpaidDays)->toBe(0)
        ->and($result->line)->toBeNull();
});

it('returns zero reduction for a semi-monthly first-half period', function (): void {
    $result = ($this->action)($this->profile, PayPeriodInput::semiMonthlyFirst(2026, 5), Money::fromCentavos(1_500_000));

    expect($result->reduction->centavos())->toBe(0)
        ->and($result->unpaidDays)->toBe(0)
        ->and($result->line)->toBeNull();
});

it('returns zero reduction for a semi-monthly second-half period', function (): void {
    $result = ($this->action)($this->profile, PayPeriodInput::semiMonthlySecond(2026, 5), Money::fromCentavos(1_500_000));

    expect($result->reduction->centavos())->toBe(0)
        ->and($result->unpaidDays)->toBe(0)
        ->and($result->line)->toBeNull();
});

it('returns zero reduction even for a zero-basic-pay employee', function (): void {
    $result = ($this->action)($this->profile, PayPeriodInput::monthly(2026, 5), Money::zero());

    expect($result->reduction->centavos())->toBe(0)
        ->and($result->unpaidDays)->toBe(0)
        ->and($result->line)->toBeNull();
});

it('returns zero reduction for a high-basic-pay employee', function (): void {
    $highEarner = EmployeeProfile::factory()->make(['basic_salary_centavos' => 15_000_000]);

    $result = ($this->action)($highEarner, PayPeriodInput::monthly(2026, 5), Money::fromCentavos(15_000_000));

    expect($result->reduction->centavos())->toBe(0)
        ->and($result->unpaidDays)->toBe(0)
        ->and($result->line)->toBeNull();
});

it('returns the canonical zero VO (the same shape as UnpaidDaysReduction::zero())', function (): void {
    $result = ($this->action)($this->profile, PayPeriodInput::monthly(2026, 5), Money::fromCentavos(3_000_000));

    // Pin the exact shape so the engine can rely on `line === null` as
    // the "no row to emit" signal rather than checking unpaidDays === 0.
    expect($result->reduction->isZero())->toBeTrue()
        ->and($result->unpaidDays)->toBe(0)
        ->and($result->line)->toBeNull();
});
