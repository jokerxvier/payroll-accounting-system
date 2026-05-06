<?php

declare(strict_types=1);

use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use App\Models\Pas\StatutoryContribution;
use Database\Seeders\DemoCatalogSeeder;

/*
 * DemoCatalogSeeder coverage.
 *
 * Pins the demo-catalog contract: the four statutory contributions, the
 * Week7 baseline catalog, and the demo-only extensions all land after one
 * `db:seed --class=DemoCatalogSeeder`. Re-running keeps row counts stable.
 */

const DEMO_CATALOG_EXTRA_DEDUCTION_CODES = [
    'multipurpose_loan',
    'cash_advance',
    'cooperative_dues',
    'tardiness',
    'absences',
    'pagibig_loan',
];

const DEMO_CATALOG_EXTRA_ALLOWANCE_CODES = [
    'clothing',
    'laundry',
    'medical_cash',
    'cola',
    'thirteenth_month_adj',
];

const DEMO_CATALOG_WEEK7_DEDUCTION_CODES = [
    'sss_loan',
    'hmo',
    'uniform',
];

const DEMO_CATALOG_WEEK7_ALLOWANCE_CODES = [
    'rice_subsidy',
    'transportation',
    'meal',
    'communication',
];

it('runs without error against a fresh database', function (): void {
    expect(fn () => $this->seed(DemoCatalogSeeder::class))->not->toThrow(Throwable::class);
});

it('seeds the four statutory contributions through composition with StatutoryContributionSeeder', function (): void {
    $this->seed(DemoCatalogSeeder::class);

    foreach (
        [
            StatutoryContribution::CODE_BIR,
            StatutoryContribution::CODE_SSS,
            StatutoryContribution::CODE_PHILHEALTH,
            StatutoryContribution::CODE_PAGIBIG,
        ] as $code
    ) {
        expect(StatutoryContribution::query()->where('contribution_code', $code)->exists())
            ->toBeTrue("expected at least one row for contribution_code={$code}");
    }
});

it('seeds the Week7 baseline catalog rows through composition', function (): void {
    $this->seed(DemoCatalogSeeder::class);

    foreach (DEMO_CATALOG_WEEK7_DEDUCTION_CODES as $code) {
        expect(DeductionType::query()->where('code', $code)->exists())
            ->toBeTrue("expected Week7 deduction code {$code}");
    }

    foreach (DEMO_CATALOG_WEEK7_ALLOWANCE_CODES as $code) {
        expect(Allowance::query()->where('code', $code)->exists())
            ->toBeTrue("expected Week7 allowance code {$code}");
    }
});

it('adds the six demo-only deduction types on top of the Week7 baseline', function (): void {
    $this->seed(DemoCatalogSeeder::class);

    foreach (DEMO_CATALOG_EXTRA_DEDUCTION_CODES as $code) {
        expect(DeductionType::query()->where('code', $code)->exists())
            ->toBeTrue("expected demo deduction code {$code}");
    }
});

it('adds the five demo-only allowances on top of the Week7 baseline', function (): void {
    $this->seed(DemoCatalogSeeder::class);

    foreach (DEMO_CATALOG_EXTRA_ALLOWANCE_CODES as $code) {
        expect(Allowance::query()->where('code', $code)->exists())
            ->toBeTrue("expected demo allowance code {$code}");
    }
});

it('is idempotent — re-running the seeder does not duplicate rows', function (): void {
    $this->seed(DemoCatalogSeeder::class);

    $contributionsAfterFirst = StatutoryContribution::query()->count();
    $deductionsAfterFirst = DeductionType::query()->count();
    $allowancesAfterFirst = Allowance::query()->count();

    $this->seed(DemoCatalogSeeder::class);

    expect(StatutoryContribution::query()->count())->toBe($contributionsAfterFirst);
    expect(DeductionType::query()->count())->toBe($deductionsAfterFirst);
    expect(Allowance::query()->count())->toBe($allowancesAfterFirst);
});

it('produces at least one row per catalog category', function (): void {
    $this->seed(DemoCatalogSeeder::class);

    // 4 statutory codes × ≥ 1 row each.
    expect(StatutoryContribution::query()->count())->toBeGreaterThanOrEqual(4);
    // Week7 (3) + demo (6) = 9.
    expect(DeductionType::query()->count())->toBeGreaterThanOrEqual(9);
    // Week7 (4) + demo (5) = 9.
    expect(Allowance::query()->count())->toBeGreaterThanOrEqual(9);
});

it('marks the de-minimis allowances correctly with NULL caps', function (): void {
    $this->seed(DemoCatalogSeeder::class);

    foreach (['clothing', 'laundry', 'medical_cash'] as $code) {
        $row = Allowance::query()->where('code', $code)->firstOrFail();
        expect($row->is_de_minimis)->toBeTrue("expected {$code} to be de-minimis");
        expect($row->is_taxable)->toBeFalse("expected {$code} to be non-taxable");
        expect($row->de_minimis_cap_centavos)->toBeNull("expected {$code} cap to remain NULL pending Q3 BIR confirmation");
    }
});

it('flags tardiness and absences as taxable since they shrink the taxable basis', function (): void {
    $this->seed(DemoCatalogSeeder::class);

    foreach (['tardiness', 'absences'] as $code) {
        $row = DeductionType::query()->where('code', $code)->firstOrFail();
        expect($row->is_taxable)->toBeTrue("expected {$code} to be taxable");
        expect($row->source)->toBe(DeductionType::SOURCE_EMPLOYEE);
    }
});

it('does not overwrite admin edits on re-run (operational fields preserved)', function (): void {
    $this->seed(DemoCatalogSeeder::class);

    // Admin edits the demo-installed `cash_advance` row through the catalog
    // UI — flips active off and rewrites notes.
    DeductionType::query()->where('code', 'cash_advance')->update([
        'is_active' => false,
        'notes' => 'EDITED BY ADMIN',
    ]);

    $this->seed(DemoCatalogSeeder::class);

    $row = DeductionType::query()->where('code', 'cash_advance')->firstOrFail();
    expect($row->is_active)->toBeFalse('admin edit to is_active must survive re-seed');
    expect($row->notes)->toBe('EDITED BY ADMIN', 'admin edit to notes must survive re-seed');
});
