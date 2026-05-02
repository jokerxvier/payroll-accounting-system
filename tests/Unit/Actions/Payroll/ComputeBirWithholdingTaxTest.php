<?php

declare(strict_types=1);

use App\Actions\Payroll\ComputeBirWithholdingTax;
use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\Exceptions\NoEffectiveContributionException;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Resolver-backed tests for ComputeBirWithholdingTax.
 *
 * BIR's bracket tables are period-specific in the seed data: the strategy
 * reads `context.period_type` to pick `monthly` vs `semi_monthly`. The
 * action threads the period frequency through unchanged — there is NO
 * post-resolver halving for BIR, unlike the contribution actions.
 *
 * Reference inputs / expected employee-share tax:
 *
 *   monthly,      taxable = 30,000.00 (3,000,000 centavos):
 *     bracket    [2,083,300 .. 3,333,300), base_tax=0, rate=15%, excess_over=2,083,300
 *     excess     = 3,000,000 - 2,083,300 = 916,700
 *     tax        = 0 + 916,700 × 1500 / 10,000 = 137,505
 *
 *   semi-monthly, taxable = 15,000.00 (1,500,000 centavos):
 *     bracket    [1,041,700 .. 1,666,700), base_tax=0, rate=15%, excess_over=1,041,700
 *     excess     = 1,500,000 - 1,041,700 = 458,300
 *     tax        = 0 + 458,300 × 1500 / 10,000 = 68,745
 */

beforeEach(function (): void {
    $this->action = app(ComputeBirWithholdingTax::class);
});

it('returns the monthly bracket employeeShare as a Money for a monthly period', function (): void {
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
    ]);

    $tax = ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::monthly(2026, 5));

    expect($tax)->toBeInstanceOf(Money::class)
        ->and($tax->centavos())->toBe(137_505);
});

it('returns the semi-monthly bracket employeeShare as a Money for a semi-monthly period', function (): void {
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
    ]);

    // Semi-monthly path picks the `semi_monthly` brackets via context.period_type.
    // No halving step — BIR table is already period-specific.
    $tax = ($this->action)(Money::fromCentavos(1_500_000), PayPeriodInput::semiMonthlyFirst(2026, 5));

    expect($tax)->toBeInstanceOf(Money::class)
        ->and($tax->centavos())->toBe(68_745);
});

it('returns zero tax when taxable income is in the lowest (zero-rate) bracket', function (): void {
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
    ]);

    // 1,000,000 centavos sits inside [0, 2_083_300): base_tax=0, rate=0.
    $tax = ($this->action)(Money::fromCentavos(1_000_000), PayPeriodInput::monthly(2026, 5));

    expect($tax->centavos())->toBe(0);
});

it('propagates NoEffectiveContributionException when no BIR row is seeded', function (): void {
    expect(fn () => ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::monthly(2026, 5)))
        ->toThrow(NoEffectiveContributionException::class);
});
