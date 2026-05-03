<?php

declare(strict_types=1);

use App\Actions\Payroll\ApplyEmployeeDeductions;
use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeProfile;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Centavo-exact tests for ApplyEmployeeDeductions.
 *
 * Reference period is May 2026 monthly (31 days). Reference grossForPercent
 * is 30,000.00 PHP (3,000,000 centavos) for percent-based math, matching
 * the engine's typical mid-tier monthly basis.
 */

beforeEach(function (): void {
    $this->action = new ApplyEmployeeDeductions;
    $this->profile = EmployeeProfile::factory()->create();
    $this->period = PayPeriodInput::monthly(2026, 5);
    $this->gross = Money::fromCentavos(3_000_000);
});

it('returns zero when the employee has no deduction subscriptions', function (): void {
    $result = ($this->action)($this->profile, $this->period, $this->gross);

    expect($result->employee->centavos())->toBe(0)
        ->and($result->employer->centavos())->toBe(0)
        ->and($result->taxableImpact->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('applies a fixed-amount employee-borne deduction', function (): void {
    $type = DeductionType::factory()->hmo()->create(); // is_taxable=false, source=employee, calc=fixed
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'deduction_type_id' => $type->id,
        'amount_centavos' => 50_000, // PHP 500
        'percent_basis_points' => null,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, $this->period, $this->gross);

    expect($result->employee->centavos())->toBe(50_000)
        ->and($result->employer->centavos())->toBe(0)
        ->and($result->taxableImpact->centavos())->toBe(0) // hmo is non-taxable
        ->and($result->lines)->toHaveCount(1)
        ->and($result->lines[0]->code)->toBe(PayrollLineItem::CODE_CUSTOM_DEDUCTION)
        ->and($result->lines[0]->bucket)->toBe(PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION)
        ->and($result->lines[0]->amount->centavos())->toBe(50_000);
});

it('computes a percent-based deduction against grossForPercent with banker rounding', function (): void {
    // 250 BPs = 2.50% → 3,000,000 × 250 / 10,000 = 75,000.00 centavos exact.
    $type = DeductionType::factory()->percent()->create(['is_taxable' => true, 'source' => 'employee']);
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'deduction_type_id' => $type->id,
        'amount_centavos' => null,
        'percent_basis_points' => 250,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, $this->period, $this->gross);

    expect($result->employee->centavos())->toBe(75_000)
        ->and($result->taxableImpact->centavos())->toBe(75_000) // taxable employee deduction
        ->and($result->lines[0]->amount->centavos())->toBe(75_000);
});

it('rounds a percent calculation halfway-case to even (banker rule)', function (): void {
    // dividedBy uses banker's rounding. With basis points = 1 the divisor
    // is 10_000 cents. We pick a gross whose product / 10_000 lands exactly
    // on a half-cent.
    //   gross = 25_000 cents → 25_000 / 10_000 = 2.5 → halfway, quotient
    //   2 is EVEN → rounds down to 2.
    //   gross = 35_000 cents → 35_000 / 10_000 = 3.5 → halfway, quotient
    //   3 is ODD → rounds up to 4.
    $type = DeductionType::factory()->percent()->create(['is_taxable' => true, 'source' => 'employee']);
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'deduction_type_id' => $type->id,
        'amount_centavos' => null,
        'percent_basis_points' => 1,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, $this->period, Money::fromCentavos(25_000));
    expect($result->employee->centavos())->toBe(2);

    $profile2 = EmployeeProfile::factory()->create();
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile2->id,
        'deduction_type_id' => $type->id,
        'amount_centavos' => null,
        'percent_basis_points' => 1,
        'schedule' => 'every_run',
    ]);
    $result2 = ($this->action)($profile2, $this->period, Money::fromCentavos(35_000));
    expect($result2->employee->centavos())->toBe(4);
});

it('skips an employer-only deduction from the employee bucket', function (): void {
    $type = DeductionType::factory()->employerOnly()->create(['is_taxable' => true]);
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'deduction_type_id' => $type->id,
        'amount_centavos' => 80_000,
        'percent_basis_points' => null,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, $this->period, $this->gross);

    expect($result->employee->centavos())->toBe(0)
        ->and($result->employer->centavos())->toBe(80_000)
        ->and($result->taxableImpact->centavos())->toBe(0) // employer-borne never affects tax base
        ->and($result->lines)->toHaveCount(1)
        ->and($result->lines[0]->bucket)->toBe(PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION);
});

it('contributes a both-source deduction to BOTH buckets at full amount', function (): void {
    $type = DeductionType::factory()->create([
        'source' => 'both',
        'is_taxable' => false,
        'calc_method' => 'fixed',
    ]);
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'deduction_type_id' => $type->id,
        'amount_centavos' => 60_000,
        'percent_basis_points' => null,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, $this->period, $this->gross);

    expect($result->employee->centavos())->toBe(60_000)
        ->and($result->employer->centavos())->toBe(60_000)
        ->and($result->taxableImpact->centavos())->toBe(0); // non-taxable
});

it('reflects taxable impact only for taxable employee-borne deductions', function (): void {
    $taxable = DeductionType::factory()->uniform()->create(); // is_taxable=true, source=employee
    $nonTaxable = DeductionType::factory()->hmo()->create(); // is_taxable=false, source=employee

    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'deduction_type_id' => $taxable->id,
        'amount_centavos' => 40_000,
        'schedule' => 'every_run',
    ]);
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'deduction_type_id' => $nonTaxable->id,
        'amount_centavos' => 70_000,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, $this->period, $this->gross);

    expect($result->employee->centavos())->toBe(110_000)
        ->and($result->taxableImpact->centavos())->toBe(40_000); // only the uniform line
});

it('skips a subscription whose schedule does not fire on this period', function (): void {
    $type = DeductionType::factory()->hmo()->create();
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'deduction_type_id' => $type->id,
        'amount_centavos' => 50_000,
        'schedule' => 'first_half', // never fires on a monthly run
    ]);

    $result = ($this->action)($this->profile, $this->period, $this->gross);

    expect($result->employee->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('excludes a subscription whose effective_to has already passed', function (): void {
    $type = DeductionType::factory()->hmo()->create();
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'deduction_type_id' => $type->id,
        'amount_centavos' => 50_000,
        'schedule' => 'every_run',
        'effective_from' => '2024-01-01',
        'effective_to' => '2026-04-30', // closed before May
    ]);

    $result = ($this->action)($this->profile, $this->period, $this->gross);

    expect($result->employee->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});
