<?php

declare(strict_types=1);

use App\Actions\Payroll\ComputePagibigContribution;
use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\Exceptions\NoEffectiveContributionException;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Resolver-backed tests for ComputePagibigContribution.
 *
 * Reference monthly basis: 30,000.00 PHP (3,000,000 centavos). The seeded
 * factory's max_msc is 5,000.00 PHP (500,000 centavos), so the input is
 * capped to 500,000. cappedInput > threshold (150,000) → upper tier
 * (ee_bp=200, er_bp=200). Result:
 *   employeeShare  = 500,000 × 200 / 10,000 = 10,000
 *   employerShare  = 500,000 × 200 / 10,000 = 10,000
 *   employerEcShare = 0
 *   taxableAmount  = 500,000
 */

beforeEach(function (): void {
    $this->action = app(ComputePagibigContribution::class);
});

it('returns the resolver result unchanged for a monthly period', function (): void {
    StatutoryContribution::factory()->pagibig()->create([
        'effective_from' => '2024-01-01',
    ]);

    $result = ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::monthly(2026, 5));

    expect($result->employeeShare->centavos())->toBe(10_000)
        ->and($result->employerShare->centavos())->toBe(10_000)
        ->and($result->employerEcShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(500_000);
});

it('halves every Money field for a semi-monthly period', function (): void {
    StatutoryContribution::factory()->pagibig()->create([
        'effective_from' => '2024-01-01',
    ]);

    $result = ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::semiMonthlyFirst(2026, 5));

    // 10,000 / 2 = 5,000 (exact); 0 / 2 = 0; 500,000 / 2 = 250,000.
    expect($result->employeeShare->centavos())->toBe(5_000)
        ->and($result->employerShare->centavos())->toBe(5_000)
        ->and($result->employerEcShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(250_000);
});

it('propagates NoEffectiveContributionException when no Pag-IBIG row is seeded', function (): void {
    expect(fn () => ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::monthly(2026, 5)))
        ->toThrow(NoEffectiveContributionException::class);
});
