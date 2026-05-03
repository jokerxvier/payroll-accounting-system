<?php

declare(strict_types=1);

use App\Actions\Payroll\ApplyAllowances;
use App\Models\Pas\Allowance;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeProfile;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\PayPeriodInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Centavo-exact tests for ApplyAllowances.
 *
 * Each case persists an employee + allowance catalog row + subscription via
 * factories, runs the action against a known period, and asserts every
 * Money centavo. Reference month is May 2026 (31 days, monthly + both
 * semi-monthly halves available).
 */

beforeEach(function (): void {
    $this->action = new ApplyAllowances;
    $this->profile = EmployeeProfile::factory()->create();
});

it('returns zero when the employee has no allowance subscriptions', function (): void {
    $period = PayPeriodInput::monthly(2026, 5);

    $result = ($this->action)($this->profile, $period);

    expect($result->taxable->centavos())->toBe(0)
        ->and($result->nonTaxable->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('emits a single taxable line for an every_run × monthly subscription', function (): void {
    $allowance = Allowance::factory()->transportation()->create();
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 300_000, // PHP 3,000
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, PayPeriodInput::monthly(2026, 5));

    expect($result->taxable->centavos())->toBe(300_000)
        ->and($result->nonTaxable->centavos())->toBe(0)
        ->and($result->lines)->toHaveCount(1)
        ->and($result->lines[0]->code)->toBe(PayrollLineItem::CODE_ALLOWANCE_TAXABLE)
        ->and($result->lines[0]->amount->centavos())->toBe(300_000)
        ->and($result->lines[0]->bucket)->toBe(PayrollLineItem::BUCKET_EARNING)
        ->and($result->lines[0]->meta['allowance_id'])->toBe($allowance->id);
});

it('routes a non-taxable subscription into the non-taxable bucket', function (): void {
    $allowance = Allowance::factory()->create([
        'is_taxable' => false,
        'is_de_minimis' => false,
    ]);
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 200_000,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, PayPeriodInput::monthly(2026, 5));

    expect($result->taxable->centavos())->toBe(0)
        ->and($result->nonTaxable->centavos())->toBe(200_000)
        ->and($result->lines[0]->code)->toBe(PayrollLineItem::CODE_ALLOWANCE_NON_TAXABLE);
});

it('clamps a de-minimis subscription to its configured cap', function (): void {
    $allowance = Allowance::factory()->create([
        'is_taxable' => false,
        'is_de_minimis' => true,
        'de_minimis_cap_centavos' => 200_000, // PHP 2,000 cap
    ]);
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 350_000, // PHP 3,500 — exceeds cap
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, PayPeriodInput::monthly(2026, 5));

    expect($result->nonTaxable->centavos())->toBe(200_000)
        ->and($result->lines[0]->amount->centavos())->toBe(200_000);
});

it('does not clamp a de-minimis subscription with a null cap', function (): void {
    // NULL cap = "not yet configured" — passes through unchanged.
    $allowance = Allowance::factory()->create([
        'is_taxable' => false,
        'is_de_minimis' => true,
        'de_minimis_cap_centavos' => null,
    ]);
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 350_000,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, PayPeriodInput::monthly(2026, 5));

    expect($result->nonTaxable->centavos())->toBe(350_000)
        ->and($result->lines[0]->amount->centavos())->toBe(350_000);
});

it('skips a first_half subscription on a monthly run', function (): void {
    $allowance = Allowance::factory()->transportation()->create();
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 300_000,
        'schedule' => 'first_half',
    ]);

    $result = ($this->action)($this->profile, PayPeriodInput::monthly(2026, 5));

    expect($result->taxable->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('fires a first_half subscription on the first-half semi-monthly run', function (): void {
    $allowance = Allowance::factory()->transportation()->create();
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 150_000,
        'schedule' => 'first_half',
    ]);

    $result = ($this->action)($this->profile, PayPeriodInput::semiMonthlyFirst(2026, 5));

    expect($result->taxable->centavos())->toBe(150_000)
        ->and($result->lines)->toHaveCount(1);
});

it('skips a first_half subscription on the second-half semi-monthly run', function (): void {
    $allowance = Allowance::factory()->transportation()->create();
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 150_000,
        'schedule' => 'first_half',
    ]);

    $result = ($this->action)($this->profile, PayPeriodInput::semiMonthlySecond(2026, 5));

    expect($result->taxable->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('sums multiple active subscriptions across both buckets', function (): void {
    $taxable = Allowance::factory()->transportation()->create(); // taxable
    $nonTaxable = Allowance::factory()->riceSubsidy()->create(); // non-taxable, de-minimis, null cap
    $secondTaxable = Allowance::factory()->meal()->create(); // taxable

    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $taxable->id,
        'amount_centavos' => 300_000,
        'schedule' => 'every_run',
    ]);
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $nonTaxable->id,
        'amount_centavos' => 200_000,
        'schedule' => 'every_run',
    ]);
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $secondTaxable->id,
        'amount_centavos' => 100_000,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, PayPeriodInput::monthly(2026, 5));

    expect($result->taxable->centavos())->toBe(400_000)
        ->and($result->nonTaxable->centavos())->toBe(200_000)
        ->and($result->lines)->toHaveCount(3);
});

it('excludes a subscription whose effective_to has already passed', function (): void {
    // effective_to is exclusive: a subscription closed on 2026-05-30 is
    // NOT active on 2026-05-31. The May monthly period's lookup date is
    // 2026-05-31 (period end), so this row should be filtered out.
    $allowance = Allowance::factory()->transportation()->create();
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 300_000,
        'schedule' => 'every_run',
        'effective_from' => '2024-01-01',
        'effective_to' => '2026-05-30', // closed mid-period
    ]);

    $result = ($this->action)($this->profile, PayPeriodInput::monthly(2026, 5));

    expect($result->taxable->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('excludes a subscription whose effective_from is after the period ends', function (): void {
    $allowance = Allowance::factory()->transportation()->create();
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 300_000,
        'schedule' => 'every_run',
        'effective_from' => '2026-06-01', // starts next month
        'effective_to' => null,
    ]);

    $result = ($this->action)($this->profile, PayPeriodInput::monthly(2026, 5));

    expect($result->taxable->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});
