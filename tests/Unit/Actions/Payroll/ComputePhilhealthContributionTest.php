<?php

declare(strict_types=1);

use App\Actions\Payroll\ComputePhilhealthContribution;
use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\Exceptions\NoEffectiveContributionException;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Resolver-backed tests for ComputePhilhealthContribution.
 *
 * Reference monthly basis: 30,000.00 PHP (3,000,000 centavos), inside the
 * seeded factory's [floor=10,000.00, ceiling=100,000.00] range, so cappedInput
 * = input. With rate_bp=500 (5%), 50/50 split, the resolver returns:
 *   total          = 3,000,000 × 500 / 10,000 = 150,000
 *   employeeShare  = 150,000 × 5,000 / 10,000 =  75,000
 *   employerShare  = 150,000 - 75,000          =  75,000
 *   employerEcShare = 0
 *   taxableAmount  = 3,000,000
 */

beforeEach(function (): void {
    $this->action = app(ComputePhilhealthContribution::class);
});

it('returns the resolver result unchanged for a monthly period', function (): void {
    StatutoryContribution::factory()->philhealth()->create([
        'effective_from' => '2024-01-01',
    ]);

    $result = ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::monthly(2026, 5));

    expect($result->employeeShare->centavos())->toBe(75_000)
        ->and($result->employerShare->centavos())->toBe(75_000)
        ->and($result->employerEcShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(3_000_000);
});

it('halves every Money field for a semi-monthly period', function (): void {
    StatutoryContribution::factory()->philhealth()->create([
        'effective_from' => '2024-01-01',
    ]);

    $result = ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::semiMonthlyFirst(2026, 5));

    // 75,000 / 2 = 37,500 (exact); 0 / 2 = 0; 3,000,000 / 2 = 1,500,000.
    expect($result->employeeShare->centavos())->toBe(37_500)
        ->and($result->employerShare->centavos())->toBe(37_500)
        ->and($result->employerEcShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(1_500_000);
});

it('propagates NoEffectiveContributionException when no PhilHealth row is seeded', function (): void {
    expect(fn () => ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::monthly(2026, 5)))
        ->toThrow(NoEffectiveContributionException::class);
});
