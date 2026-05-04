<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\StatutoryContributionRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\StatutoryContributionSeeder;
use Illuminate\Database\Eloquent\Collection;

/*
 * Registry-level tests: verify date filtering and the deterministic
 * application ordering (application_order ASC, contribution_code ASC) the
 * payroll engine relies on. Per-row computation is covered by the
 * StatutoryContributionResolver suite — these cases focus on which rows
 * come back, and in what order.
 */

it('returns the four PH contributions in canonical application order', function () {
    $this->seed(StatutoryContributionSeeder::class);

    $registry = app(StatutoryContributionRegistry::class);

    $rows = $registry->active(CarbonImmutable::parse('2026-05-04'));

    expect($rows)->toBeInstanceOf(Collection::class)
        ->and($rows)->toHaveCount(4)
        ->and($rows->pluck('contribution_code')->all())->toBe([
            StatutoryContribution::CODE_SSS,         // application_order 10
            StatutoryContribution::CODE_PHILHEALTH,  // application_order 20
            StatutoryContribution::CODE_PAGIBIG,     // application_order 30
            StatutoryContribution::CODE_BIR,         // application_order 90
        ]);
});

it('excludes a contribution whose effective_to is on or before the requested date', function () {
    StatutoryContribution::factory()->bir()->create([
        'contribution_code' => 'EXPIRED_BIR',
        'effective_from' => '2024-01-01',
        'effective_to' => '2025-01-01', // exclusive — not active on or after 2025-01-01
        'application_order' => 5,
    ]);

    $registry = app(StatutoryContributionRegistry::class);

    expect($registry->active(CarbonImmutable::parse('2025-01-01')))->toHaveCount(0)
        ->and($registry->active(CarbonImmutable::parse('2025-06-15')))->toHaveCount(0)
        ->and($registry->active(CarbonImmutable::parse('2024-12-31')))->toHaveCount(1);
});

it('excludes a contribution whose effective_from is after the requested date', function () {
    StatutoryContribution::factory()->sss()->create([
        'contribution_code' => 'FUTURE_SSS',
        'effective_from' => '2027-01-01',
        'effective_to' => null,
        'application_order' => 5,
    ]);

    $registry = app(StatutoryContributionRegistry::class);

    expect($registry->active(CarbonImmutable::parse('2026-05-04')))->toHaveCount(0)
        ->and($registry->active(CarbonImmutable::parse('2027-01-01')))->toHaveCount(1);
});

it('places a custom contribution in the correct slot by application_order', function () {
    $this->seed(StatutoryContributionSeeder::class);

    StatutoryContribution::factory()->philhealth()->create([
        'contribution_code' => 'CUSTOM_HMO',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
        'application_order' => 50, // between Pag-IBIG (30) and BIR (90)
    ]);

    $registry = app(StatutoryContributionRegistry::class);

    $codes = $registry->active(CarbonImmutable::parse('2026-05-04'))->pluck('contribution_code')->all();

    expect($codes)->toBe([
        StatutoryContribution::CODE_SSS,
        StatutoryContribution::CODE_PHILHEALTH,
        StatutoryContribution::CODE_PAGIBIG,
        'CUSTOM_HMO',
        StatutoryContribution::CODE_BIR,
    ]);
});

it('breaks ties on equal application_order by contribution_code ASC', function () {
    StatutoryContribution::factory()->philhealth()->create([
        'contribution_code' => 'ZETA_FUND',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
        'application_order' => 100,
    ]);

    StatutoryContribution::factory()->philhealth()->create([
        'contribution_code' => 'ALPHA_FUND',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
        'application_order' => 100,
    ]);

    $registry = app(StatutoryContributionRegistry::class);

    $codes = $registry->active(CarbonImmutable::parse('2026-05-04'))->pluck('contribution_code')->all();

    expect($codes)->toBe(['ALPHA_FUND', 'ZETA_FUND']);
});

it('returns an empty Collection when no contributions are active for the given date', function () {
    $this->seed(StatutoryContributionSeeder::class);

    $registry = app(StatutoryContributionRegistry::class);

    $rows = $registry->active(CarbonImmutable::parse('1999-01-01'));

    expect($rows)->toBeInstanceOf(Collection::class)
        ->and($rows)->toBeEmpty();
});

it('excludes voided rows even when within the effective window', function () {
    $this->seed(StatutoryContributionSeeder::class);

    // Slot a custom contribution well inside the effective window for the
    // probe date (2026-05-04), then void it. The four seeded PH rows must
    // still come back; the voided custom row must not.
    StatutoryContribution::factory()->bir()->create([
        'contribution_code' => 'EXTRA_BIR',
        'effective_from' => '2024-01-01',
        'effective_to' => null,
        'application_order' => 5,
        'voided_at' => CarbonImmutable::parse('2024-06-01'),
        'voided_by_user_id' => null,
    ]);

    $registry = app(StatutoryContributionRegistry::class);

    $codes = $registry->active(CarbonImmutable::parse('2026-05-04'))->pluck('contribution_code')->all();

    expect($codes)->not->toContain('EXTRA_BIR')
        ->and($codes)->toBe([
            StatutoryContribution::CODE_SSS,
            StatutoryContribution::CODE_PHILHEALTH,
            StatutoryContribution::CODE_PAGIBIG,
            StatutoryContribution::CODE_BIR,
        ]);
});
