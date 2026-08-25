<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * AccountingCatalogSeeder — the default Philippine school chart of accounts
 * and tax-rate catalog.
 *
 * Pinned behaviours:
 *  - Every seeded account's normal_balance agrees with the rule in
 *    ChartOfAccount::normalBalanceForType(). A seeded row with the wrong
 *    normal balance would sign-flip that account in every report.
 *  - All six system accounts exist, are locked, and are unique.
 *  - The VAT rates are wired to the right system accounts.
 *  - Re-running the seeder changes nothing and duplicates nothing — ids must
 *    stay stable because Slice 2's journal lines will reference them.
 *  - Only the default school is seeded; other schools receive their copy via
 *    SchoolObserver.
 */

beforeEach(function (): void {
    TaxRate::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();
});

it('seeds a chart of accounts for the default school', function () {
    $this->seed(AccountingCatalogSeeder::class);

    expect(ChartOfAccount::query()->count())->toBeGreaterThan(20);
});

it('gives every seeded account the normal balance its type implies', function () {
    $this->seed(AccountingCatalogSeeder::class);

    // The whole point of storing normal_balance rather than deriving it at
    // read time is that reports can trust the column. If a seeded row
    // disagreed with the rule, every figure on that account would be
    // reported with the wrong sign.
    foreach (ChartOfAccount::query()->get() as $account) {
        expect($account->normal_balance)
            ->toBe(
                ChartOfAccount::normalBalanceForType($account->type),
                "Account {$account->code} ({$account->type}) has the wrong normal balance",
            );
    }
});

it('seeds all six system accounts, each locked and unique', function () {
    $this->seed(AccountingCatalogSeeder::class);

    foreach (ChartOfAccount::SYSTEM_CODES as $systemCode) {
        $matches = ChartOfAccount::query()->where('system_code', $systemCode)->get();

        expect($matches)->toHaveCount(1, "Expected exactly one {$systemCode} account");
        expect($matches->first()->is_locked)->toBeTrue("{$systemCode} must be locked");
    }
});

it('classifies the accounts across all five types', function () {
    $this->seed(AccountingCatalogSeeder::class);

    foreach (ChartOfAccount::TYPES as $type) {
        expect(ChartOfAccount::query()->ofType($type)->exists())
            ->toBeTrue("Expected at least one {$type} account in the default chart");
    }
});

it('marks depreciation as a non-cash account', function () {
    $this->seed(AccountingCatalogSeeder::class);

    // Depreciation is an operating expense but not an operating CASH flow —
    // the distinction the Cash Flow Statement depends on, and the reason
    // cash_flow_category cannot be inferred from `type`.
    expect(ChartOfAccount::query()->where('code', '5300')->value('cash_flow_category'))
        ->toBe(ChartOfAccount::CASH_FLOW_NONE);

    // Interest expense is an expense too, but a financing cash flow.
    expect(ChartOfAccount::query()->where('code', '5400')->value('cash_flow_category'))
        ->toBe(ChartOfAccount::CASH_FLOW_FINANCING);

    // Salaries are the ordinary operating case.
    expect(ChartOfAccount::query()->where('code', '5100')->value('cash_flow_category'))
        ->toBe(ChartOfAccount::CASH_FLOW_OPERATING);
});

it('marks exactly the two cash accounts as holding cash', function () {
    $this->seed(AccountingCatalogSeeder::class);

    $cashCodes = ChartOfAccount::query()
        ->where('is_cash_equivalent', true)
        ->orderBy('code')
        ->pluck('code')
        ->all();

    // Being an operating asset is not the same as being cash: 1400 Prepaid
    // Expenses and 1200 Accounts Receivable are both operating assets, and
    // money cannot be paid out of either.
    expect($cashCodes)->toBe(['1100', '1110']);
});

it('wires the VAT rates to their system accounts', function () {
    $this->seed(AccountingCatalogSeeder::class);

    $outputVatId = ChartOfAccount::query()
        ->where('system_code', ChartOfAccount::SYSTEM_VAT_OUTPUT)
        ->value('id');
    $inputVatId = ChartOfAccount::query()
        ->where('system_code', ChartOfAccount::SYSTEM_VAT_INPUT)
        ->value('id');

    expect(TaxRate::query()->where('code', 'VAT_12_SALES')->value('account_id'))
        ->toBe($outputVatId);
    expect(TaxRate::query()->where('code', 'VAT_12_PURCHASE')->value('account_id'))
        ->toBe($inputVatId);
});

it('seeds exempt and zero-rated as distinct zero-rate rows with no account', function () {
    $this->seed(AccountingCatalogSeeder::class);

    $exempt = TaxRate::query()->where('code', 'VAT_EXEMPT')->firstOrFail();
    $zeroRated = TaxRate::query()->where('code', 'VAT_ZERO')->firstOrFail();

    expect($exempt->rate_bps)->toBe(0)
        ->and($exempt->account_id)->toBeNull()
        ->and($exempt->type)->toBe(TaxRate::TYPE_EXEMPT);

    expect($zeroRated->rate_bps)->toBe(0)
        ->and($zeroRated->account_id)->toBeNull()
        ->and($zeroRated->type)->toBe(TaxRate::TYPE_ZERO_RATED);
});

it('stores the VAT rate in basis points', function () {
    $this->seed(AccountingCatalogSeeder::class);

    expect(TaxRate::query()->where('code', 'VAT_12_SALES')->value('rate_bps'))
        ->toBe(1200);
});

it('is idempotent and keeps account ids stable across re-runs', function () {
    $this->seed(AccountingCatalogSeeder::class);

    $countBefore = ChartOfAccount::query()->count();
    $rateCountBefore = TaxRate::query()->count();
    $idsBefore = ChartOfAccount::query()->orderBy('code')->pluck('id', 'code')->all();

    $this->seed(AccountingCatalogSeeder::class);

    expect(ChartOfAccount::query()->count())->toBe($countBefore)
        ->and(TaxRate::query()->count())->toBe($rateCountBefore);

    // Stability matters: Slice 2's journal lines FK to these ids, so a
    // re-seed must never renumber the chart.
    expect(ChartOfAccount::query()->orderBy('code')->pluck('id', 'code')->all())
        ->toBe($idsBefore);
});

it('seeds only the default school', function () {
    $other = School::factory()->create(['slug' => 'seeder-scope-check']);

    // The observer already cloned an (empty) catalog for the new school.
    // Wipe it so we can prove the seeder itself does not target other schools.
    ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $other->getKey())->delete();

    $this->seed(AccountingCatalogSeeder::class);

    expect(
        ChartOfAccount::query()->withoutGlobalScopes()
            ->where('school_id', $other->getKey())->count()
    )->toBe(0);

    expect(ChartOfAccount::query()->count())->toBeGreaterThan(0);
});
