<?php

declare(strict_types=1);

use App\Actions\Payroll\ApplyPayrollAdjustments;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\PayPeriodInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Centavo-exact tests for ApplyPayrollAdjustments.
 *
 * Reference period is May 2026 monthly: start = 2026-05-01, end = 2026-05-31.
 * Boundary inclusion is verified at both ends and one-day-off either side.
 */

beforeEach(function (): void {
    $this->action = new ApplyPayrollAdjustments;
    $this->profile = EmployeeProfile::factory()->create();
    $this->period = PayPeriodInput::monthly(2026, 5);
});

it('returns zero when the employee has no adjustments in the period', function (): void {
    $result = ($this->action)($this->profile, $this->period);

    expect($result->taxableAdditions->centavos())->toBe(0)
        ->and($result->nonTaxableAdditions->centavos())->toBe(0)
        ->and($result->deductions->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('routes a taxable addition to the taxable bucket and emits an earning line', function (): void {
    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'kind' => 'addition',
        'is_taxable' => true,
        'amount_centavos' => 500_000, // PHP 5,000
        'applies_on' => '2026-05-15',
        'label' => 'Performance Bonus',
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->taxableAdditions->centavos())->toBe(500_000)
        ->and($result->nonTaxableAdditions->centavos())->toBe(0)
        ->and($result->deductions->centavos())->toBe(0)
        ->and($result->lines)->toHaveCount(1)
        ->and($result->lines[0]->code)->toBe(PayrollLineItem::CODE_ADJUSTMENT_ADDITION)
        ->and($result->lines[0]->bucket)->toBe(PayrollLineItem::BUCKET_EARNING)
        ->and($result->lines[0]->label)->toBe('Performance Bonus')
        ->and($result->lines[0]->meta['is_taxable'])->toBeTrue();
});

it('routes a non-taxable addition to the non-taxable bucket', function (): void {
    PayrollAdjustment::factory()->nonTaxable()->create([
        'employee_profile_id' => $this->profile->id,
        'kind' => 'addition',
        'amount_centavos' => 300_000,
        'applies_on' => '2026-05-15',
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->taxableAdditions->centavos())->toBe(0)
        ->and($result->nonTaxableAdditions->centavos())->toBe(300_000)
        ->and($result->deductions->centavos())->toBe(0);
});

it('routes a deduction to the deductions bucket and emits an employee-deduction line', function (): void {
    PayrollAdjustment::factory()->deduction()->create([
        'employee_profile_id' => $this->profile->id,
        'amount_centavos' => 200_000,
        'applies_on' => '2026-05-15',
        'label' => 'Cash Advance Recovery',
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->deductions->centavos())->toBe(200_000)
        ->and($result->lines[0]->code)->toBe(PayrollLineItem::CODE_ADJUSTMENT_DEDUCTION)
        ->and($result->lines[0]->bucket)->toBe(PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION);
});

it('includes an adjustment dated exactly on the period start day', function (): void {
    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'amount_centavos' => 100_000,
        'applies_on' => '2026-05-01', // exact start
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->taxableAdditions->centavos())->toBe(100_000);
});

it('includes an adjustment dated exactly on the period end day', function (): void {
    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'amount_centavos' => 100_000,
        'applies_on' => '2026-05-31', // exact end
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->taxableAdditions->centavos())->toBe(100_000);
});

it('excludes an adjustment dated one day before the period start', function (): void {
    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'amount_centavos' => 100_000,
        'applies_on' => '2026-04-30',
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->taxableAdditions->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('excludes an adjustment dated one day after the period end', function (): void {
    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'amount_centavos' => 100_000,
        'applies_on' => '2026-06-01',
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->taxableAdditions->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('sums multiple adjustments across all three buckets', function (): void {
    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'kind' => 'addition',
        'is_taxable' => true,
        'amount_centavos' => 500_000,
        'applies_on' => '2026-05-10',
    ]);
    PayrollAdjustment::factory()->nonTaxable()->create([
        'employee_profile_id' => $this->profile->id,
        'kind' => 'addition',
        'amount_centavos' => 200_000,
        'applies_on' => '2026-05-15',
    ]);
    PayrollAdjustment::factory()->deduction()->create([
        'employee_profile_id' => $this->profile->id,
        'amount_centavos' => 150_000,
        'applies_on' => '2026-05-20',
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->taxableAdditions->centavos())->toBe(500_000)
        ->and($result->nonTaxableAdditions->centavos())->toBe(200_000)
        ->and($result->deductions->centavos())->toBe(150_000)
        ->and($result->lines)->toHaveCount(3);
});
