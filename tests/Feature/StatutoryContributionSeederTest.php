<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\StatutoryContributionResolver;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\StatutoryContributionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Smoke tests for StatutoryContributionSeeder. The seeder is the audit trail
 * for the v1 government rates, so these tests pin the shape of every row and
 * the count of generated SSS bands. Computation correctness lives in the
 * strategy unit tests; this file proves the seeded rules are well-formed and
 * that the resolver can dispatch against them end-to-end.
 */

it('inserts one row per contribution code', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    expect(StatutoryContribution::query()->count())->toBe(4)
        ->and(StatutoryContribution::query()->where('contribution_code', StatutoryContribution::CODE_BIR)->exists())->toBeTrue()
        ->and(StatutoryContribution::query()->where('contribution_code', StatutoryContribution::CODE_SSS)->exists())->toBeTrue()
        ->and(StatutoryContribution::query()->where('contribution_code', StatutoryContribution::CODE_PHILHEALTH)->exists())->toBeTrue()
        ->and(StatutoryContribution::query()->where('contribution_code', StatutoryContribution::CODE_PAGIBIG)->exists())->toBeTrue();
});

it('seeds the SSS table with 61 bands spanning MSC PHP 5,000 to PHP 35,000', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $sss = StatutoryContribution::query()
        ->where('contribution_code', StatutoryContribution::CODE_SSS)
        ->firstOrFail();

    $bands = $sss->rules['bands'];

    expect($bands)->toBeArray()
        ->and($bands)->toHaveCount(61)
        ->and($bands[0]['lower'])->toBe(0)
        ->and($bands[0]['contribution_base'])->toBe(500_000)         // PHP 5,000
        ->and($bands[60]['upper'])->toBeNull()
        ->and($bands[60]['contribution_base'])->toBe(3_500_000)      // PHP 35,000
        ->and($sss->algorithm)->toBe(StatutoryContribution::ALGORITHM_SALARY_BAND);
});

it('seeds BIR with both monthly and semi-monthly bracket tables', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $bir = StatutoryContribution::query()
        ->where('contribution_code', StatutoryContribution::CODE_BIR)
        ->firstOrFail();

    $periodTypes = $bir->rules['period_types'];
    $keys = array_keys($periodTypes);
    sort($keys);

    expect($bir->algorithm)->toBe(StatutoryContribution::ALGORITHM_BRACKET_TABLE)
        ->and($keys)->toBe(['monthly', 'semi_monthly'])
        ->and($periodTypes['monthly'])->toHaveCount(6)
        ->and($periodTypes['semi_monthly'])->toHaveCount(6);

    $monthly = $periodTypes['monthly'];

    expect($monthly[0]['lower'])->toBe(0)
        ->and($monthly[0]['excess_rate_bp'])->toBe(0)
        ->and($monthly[5]['upper'])->toBeNull()
        ->and($monthly[5]['excess_rate_bp'])->toBe(3_500)            // 35%
        ->and($monthly[5]['excess_over'])->toBe(66_666_700);         // PHP 666,667
});

it('seeds PhilHealth with the 5% rate, PHP 10,000 floor, and PHP 100,000 ceiling', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $philhealth = StatutoryContribution::query()
        ->where('contribution_code', StatutoryContribution::CODE_PHILHEALTH)
        ->firstOrFail();

    expect($philhealth->algorithm)->toBe(StatutoryContribution::ALGORITHM_PERCENTAGE_WITH_CAP)
        ->and($philhealth->rules['rate_bp'])->toBe(500)
        ->and($philhealth->rules['floor'])->toBe(1_000_000)
        ->and($philhealth->rules['ceiling'])->toBe(10_000_000)
        ->and($philhealth->rules['split']['employee_bp'])->toBe(5_000)
        ->and($philhealth->rules['split']['employer_bp'])->toBe(5_000);
});

it('seeds Pag-IBIG with the HDMF Circular 460 tiers and PHP 10,000 cap', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $pagibig = StatutoryContribution::query()
        ->where('contribution_code', StatutoryContribution::CODE_PAGIBIG)
        ->firstOrFail();

    expect($pagibig->algorithm)->toBe(StatutoryContribution::ALGORITHM_TIERED_PERCENTAGE)
        ->and($pagibig->rules['threshold'])->toBe(150_000)
        ->and($pagibig->rules['lower']['ee_bp'])->toBe(100)
        ->and($pagibig->rules['lower']['er_bp'])->toBe(200)
        ->and($pagibig->rules['upper']['ee_bp'])->toBe(200)
        ->and($pagibig->rules['upper']['er_bp'])->toBe(200)
        ->and($pagibig->rules['max_msc'])->toBe(1_000_000);
});

it('is idempotent — re-seeding does not duplicate rows', function (): void {
    $this->seed(StatutoryContributionSeeder::class);
    $this->seed(StatutoryContributionSeeder::class);

    expect(StatutoryContribution::query()->count())->toBe(4);
});

it('produces a working PhilHealth computation through the resolver', function (): void {
    $this->seed(StatutoryContributionSeeder::class);

    $result = app(StatutoryContributionResolver::class)->compute(
        StatutoryContribution::CODE_PHILHEALTH,
        Money::fromCentavos(2_000_000), // PHP 20,000 monthly basic salary
        CarbonImmutable::parse('2025-06-15'),
    );

    // 5% of PHP 20,000 = PHP 1,000 total; 50/50 split = PHP 500 each.
    expect($result->taxableAmount->centavos())->toBe(2_000_000)
        ->and($result->total()->centavos())->toBe(100_000)
        ->and($result->employeeShare->centavos())->toBe(50_000)
        ->and($result->employerShare->centavos())->toBe(50_000)
        ->and($result->employerEcShare->centavos())->toBe(0);
});
