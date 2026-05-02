<?php

declare(strict_types=1);

use App\Actions\Payroll\ComputeSssContribution;
use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\Exceptions\NoEffectiveContributionException;
use App\Services\Statutory\StatutoryContributionResolver;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Resolver-backed tests for ComputeSssContribution.
 *
 * Every case seeds an SSS row via the factory's sss() state, then invokes
 * the action through the container so the real StatutoryContributionResolver
 * (and its strategy map) is wired up. We validate the four Money fields of
 * the result rather than just the totals, because the action's halving is
 * field-by-field.
 *
 * Reference monthly basis: 30,000.00 PHP (3,000,000 centavos), which lands
 * in the top band of the seeded SSS factory data:
 *   employee_share=90,000 + employee_aux_share=0 → employeeShare 90,000
 *   employer_share=190,000                       → employerShare 190,000
 *   employer_aux_share=3,000                     → employerEcShare 3,000
 *   contribution_base=2,000,000                  → taxableAmount 2,000,000
 */

beforeEach(function (): void {
    $this->action = app(ComputeSssContribution::class);
});

it('returns the resolver result unchanged for a monthly period', function (): void {
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
    ]);

    $result = ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::monthly(2026, 5));

    expect($result->employeeShare->centavos())->toBe(90_000)
        ->and($result->employerShare->centavos())->toBe(190_000)
        ->and($result->employerEcShare->centavos())->toBe(3_000)
        ->and($result->taxableAmount->centavos())->toBe(2_000_000);
});

it('halves every Money field for a semi-monthly period', function (): void {
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
    ]);

    $result = ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::semiMonthlyFirst(2026, 5));

    // Each value halved exactly (all top-band values are even centavo counts).
    expect($result->employeeShare->centavos())->toBe(45_000)
        ->and($result->employerShare->centavos())->toBe(95_000)
        ->and($result->employerEcShare->centavos())->toBe(1_500)
        ->and($result->taxableAmount->centavos())->toBe(1_000_000);
});

it('propagates NoEffectiveContributionException when no SSS row is seeded', function (): void {
    expect(fn () => ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::monthly(2026, 5)))
        ->toThrow(NoEffectiveContributionException::class);
});

it('selects the row effective on the period end date when two versions overlap the period', function (): void {
    // Older table: in force through 2026-05-15 (effective_to is exclusive).
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => '2026-05-16',
        'rules' => [
            'bands' => [
                [
                    'lower' => 0,
                    'upper' => null,
                    'contribution_base' => 2_000_000,
                    'employee_share' => 50_000,
                    'employer_share' => 100_000,
                    'employer_aux_share' => 1_000,
                    'employee_aux_share' => 0,
                ],
            ],
        ],
    ]);

    // Newer table: takes effect 2026-05-16, governs 2026-05-31 (the period end).
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2026-05-16',
        'effective_to' => null,
        'rules' => [
            'bands' => [
                [
                    'lower' => 0,
                    'upper' => null,
                    'contribution_base' => 2_000_000,
                    'employee_share' => 90_000,
                    'employer_share' => 190_000,
                    'employer_aux_share' => 3_000,
                    'employee_aux_share' => 0,
                ],
            ],
        ],
    ]);

    $result = ($this->action)(Money::fromCentavos(3_000_000), PayPeriodInput::monthly(2026, 5));

    // Action must select the row effective on 2026-05-31, i.e. the newer one.
    expect($result->employeeShare->centavos())->toBe(90_000)
        ->and($result->employerShare->centavos())->toBe(190_000);
});

it('binds via the container so the resolver is auto-wired', function (): void {
    $resolved = app(ComputeSssContribution::class);

    expect($resolved)->toBeInstanceOf(ComputeSssContribution::class);

    // Sanity: the resolver dependency itself must resolve.
    expect(app(StatutoryContributionResolver::class))->toBeInstanceOf(StatutoryContributionResolver::class);
});
