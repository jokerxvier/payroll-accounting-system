<?php

declare(strict_types=1);

use App\Actions\Payroll\ApplyEmployeeLoans;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\Services\Payroll\PayrollLineItem;
use App\ValueObjects\PayPeriodInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Centavo-exact tests for ApplyEmployeeLoans.
 *
 * The action MUST be read-only — it previews the next amortization but
 * never calls applyAmortization() or persists state. Several cases assert
 * the loan row is identical before and after the action runs.
 *
 * Reference period: May 2026 monthly. Reference loan: PHP 30,000 principal,
 * PHP 2,500 monthly amortization (12-month payoff).
 */

beforeEach(function (): void {
    $this->action = new ApplyEmployeeLoans;
    $this->profile = EmployeeProfile::factory()->create();
    $this->period = PayPeriodInput::monthly(2026, 5);
});

it('returns zero when the employee has no open loans', function (): void {
    $result = ($this->action)($this->profile, $this->period);

    expect($result->total->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('emits a single amortization line when the balance exceeds the monthly amount', function (): void {
    $loan = EmployeeLoan::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'principal_centavos' => 3_000_000,
        'outstanding_balance_centavos' => 2_500_000, // 5 payments left
        'monthly_amortization_centavos' => 250_000,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->total->centavos())->toBe(250_000)
        ->and($result->lines)->toHaveCount(1)
        ->and($result->lines[0]->code)->toBe(PayrollLineItem::CODE_LOAN_AMORTIZATION)
        ->and($result->lines[0]->bucket)->toBe(PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION)
        ->and($result->lines[0]->amount->centavos())->toBe(250_000)
        ->and($result->lines[0]->meta['loan_id'])->toBe($loan->id);
});

it('clamps the amortization to the remaining balance on the final payment', function (): void {
    // Final payment scenario: PHP 1,000 left, PHP 2,500 monthly. Should
    // pay only PHP 1,000.
    EmployeeLoan::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'outstanding_balance_centavos' => 100_000, // PHP 1,000
        'monthly_amortization_centavos' => 250_000, // PHP 2,500
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->total->centavos())->toBe(100_000)
        ->and($result->lines[0]->amount->centavos())->toBe(100_000);
});

it('skips a loan that is already closed', function (): void {
    EmployeeLoan::factory()->closed()->create([
        'employee_profile_id' => $this->profile->id,
        'monthly_amortization_centavos' => 250_000,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->total->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('skips a loan whose outstanding balance is zero even when not yet closed', function (): void {
    // A row that's been zeroed but not yet stamped closed_on (race
    // condition window). scopeOpen() filters it out; the action must
    // match.
    EmployeeLoan::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'outstanding_balance_centavos' => 0,
        'monthly_amortization_centavos' => 250_000,
        'schedule' => 'every_run',
        'closed_on' => null,
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->total->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});

it('does NOT mutate the loan row (read-only invariant)', function (): void {
    $loan = EmployeeLoan::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'outstanding_balance_centavos' => 2_500_000,
        'monthly_amortization_centavos' => 250_000,
        'lock_version' => 7,
        'schedule' => 'every_run',
    ]);

    ($this->action)($this->profile, $this->period);

    $reloaded = $loan->fresh();
    expect($reloaded->outstanding_balance_centavos)->toBe(2_500_000)
        ->and($reloaded->lock_version)->toBe(7)
        ->and($reloaded->closed_on)->toBeNull();
});

it('sums multiple open loans in the total', function (): void {
    EmployeeLoan::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'outstanding_balance_centavos' => 1_000_000,
        'monthly_amortization_centavos' => 100_000,
        'schedule' => 'every_run',
    ]);
    EmployeeLoan::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'outstanding_balance_centavos' => 2_000_000,
        'monthly_amortization_centavos' => 250_000,
        'schedule' => 'every_run',
    ]);
    EmployeeLoan::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'outstanding_balance_centavos' => 50_000, // clamps to 50_000 (final payment)
        'monthly_amortization_centavos' => 500_000,
        'schedule' => 'every_run',
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->total->centavos())->toBe(400_000) // 100k + 250k + 50k
        ->and($result->lines)->toHaveCount(3);
});

it('skips a loan whose schedule does not fire on this period', function (): void {
    EmployeeLoan::factory()->create([
        'employee_profile_id' => $this->profile->id,
        'outstanding_balance_centavos' => 2_500_000,
        'monthly_amortization_centavos' => 250_000,
        'schedule' => 'first_half', // never fires on monthly
    ]);

    $result = ($this->action)($this->profile, $this->period);

    expect($result->total->centavos())->toBe(0)
        ->and($result->lines)->toBe([]);
});
