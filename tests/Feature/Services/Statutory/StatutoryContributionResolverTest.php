<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\Exceptions\NoEffectiveContributionException;
use App\Services\Statutory\StatutoryContributionResolver;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;

/*
 * Resolver-level tests: confirm the singleton binding works, dispatching
 * lands on the right strategy per algorithm, and the missing-row path
 * throws NoEffectiveContributionException.
 *
 * Strategy-internal math is covered by the unit tests in
 * tests/Unit/Services/Statutory/Strategies/. These cases assert just enough
 * to prove dispatch (input → expected output) without re-deriving every
 * boundary.
 */

it('dispatches to BracketTableStrategy for BIR rows', function () {
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $resolver = app(StatutoryContributionResolver::class);

    $result = $resolver->compute(
        StatutoryContribution::CODE_BIR,
        Money::fromCentavos(2_500_000),
        CarbonImmutable::parse('2024-06-15'),
        ['period_type' => 'monthly'],
    );

    // Hand-computed in BracketTableStrategyTest: 62_505 cents.
    expect($result->employeeShare->centavos())->toBe(62_505)
        ->and($result->employerShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(2_500_000);
});

it('dispatches to SalaryBandStrategy for SSS rows', function () {
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $resolver = app(StatutoryContributionResolver::class);

    $result = $resolver->compute(
        StatutoryContribution::CODE_SSS,
        Money::fromCentavos(400_000),
        CarbonImmutable::parse('2024-06-15'),
    );

    // Lowest SSS band: ee=22_500, er=47_500, er_aux=1_000, base=500_000.
    expect($result->employeeShare->centavos())->toBe(22_500)
        ->and($result->employerShare->centavos())->toBe(47_500)
        ->and($result->employerEcShare->centavos())->toBe(1_000)
        ->and($result->taxableAmount->centavos())->toBe(500_000);
});

it('dispatches to PercentageWithCapStrategy for PhilHealth rows', function () {
    StatutoryContribution::factory()->philhealth()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $resolver = app(StatutoryContributionResolver::class);

    $result = $resolver->compute(
        StatutoryContribution::CODE_PHILHEALTH,
        Money::fromCentavos(5_000_000),
        CarbonImmutable::parse('2024-06-15'),
    );

    // total = 5_000_000 × 500/10000 = 250_000; 50/50 split → 125_000 / 125_000.
    expect($result->employeeShare->centavos())->toBe(125_000)
        ->and($result->employerShare->centavos())->toBe(125_000)
        ->and($result->employerEcShare->centavos())->toBe(0)
        ->and($result->taxableAmount->centavos())->toBe(5_000_000);
});

it('dispatches to TieredPercentageStrategy for Pag-IBIG rows', function () {
    StatutoryContribution::factory()->pagibig()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $resolver = app(StatutoryContributionResolver::class);

    $result = $resolver->compute(
        StatutoryContribution::CODE_PAGIBIG,
        Money::fromCentavos(200_000),
        CarbonImmutable::parse('2024-06-15'),
    );

    // 200_000 > threshold 150_000 → upper rates ee_bp=200, er_bp=200.
    // ee = 200_000 × 200 / 10000 = 4_000; er = 4_000.
    expect($result->employeeShare->centavos())->toBe(4_000)
        ->and($result->employerShare->centavos())->toBe(4_000)
        ->and($result->taxableAmount->centavos())->toBe(200_000);
});

it('throws NoEffectiveContributionException when no row covers the requested date', function () {
    $resolver = app(StatutoryContributionResolver::class);

    expect(
        fn () => $resolver->compute(
            StatutoryContribution::CODE_SSS,
            Money::fromCentavos(1_000_000),
            CarbonImmutable::parse('2024-06-15'),
        ),
    )->toThrow(NoEffectiveContributionException::class, "No effective StatutoryContribution for code 'SSS' on date 2024-06-15");
});

it('honours effective-date precedence when multiple rows exist for the same code', function () {
    // 2024 row has different bracket numbers from 2025 — pin the dispatch by
    // setting unique base_tax values so each year's result is identifiable.
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => '2025-01-01',
        'rules' => [
            'period_types' => [
                'monthly' => [
                    ['lower' => 0, 'upper' => null, 'base_tax' => 11_111, 'excess_rate_bp' => 0, 'excess_over' => 0],
                ],
            ],
        ],
    ]);

    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2025-01-01',
        'effective_to' => null,
        'rules' => [
            'period_types' => [
                'monthly' => [
                    ['lower' => 0, 'upper' => null, 'base_tax' => 22_222, 'excess_rate_bp' => 0, 'excess_over' => 0],
                ],
            ],
        ],
    ]);

    $resolver = app(StatutoryContributionResolver::class);

    $r2024 = $resolver->compute(
        StatutoryContribution::CODE_BIR,
        Money::fromCentavos(1_000_000),
        CarbonImmutable::parse('2024-06-15'),
        ['period_type' => 'monthly'],
    );

    $r2025 = $resolver->compute(
        StatutoryContribution::CODE_BIR,
        Money::fromCentavos(1_000_000),
        CarbonImmutable::parse('2025-06-15'),
        ['period_type' => 'monthly'],
    );

    // Each year returns its own row's base_tax (only single-bracket).
    expect($r2024->employeeShare->centavos())->toBe(11_111)
        ->and($r2025->employeeShare->centavos())->toBe(22_222);
});

it('resolves the configured singleton from the service container', function () {
    $first = app(StatutoryContributionResolver::class);
    $second = app(StatutoryContributionResolver::class);

    expect($first)->toBeInstanceOf(StatutoryContributionResolver::class)
        ->and($first)->toBe($second); // same instance because singleton-bound
});
