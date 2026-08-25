<?php

declare(strict_types=1);

use App\Models\Pas\Allowance;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use App\Observers\SchoolObserver;

/*
 * SchoolObserver — cloning the Phase 5 accounting catalogs onto a new school.
 *
 * The chart of accounts and the tax-rate catalog differ from the allowance /
 * deduction-type catalogs in one critical way: they carry foreign keys to
 * OTHER ROWS IN THE SAME CLONE SET.
 *
 *   pas_chart_of_accounts.parent_id → another account
 *   pas_tax_rates.account_id        → the VAT account
 *
 * A naive row copy would leave the new school's rows pointing at the DEFAULT
 * school's ids — a silent cross-tenant leak the global scope cannot catch,
 * because the FK is resolved by id rather than through a scoped query. These
 * tests pin the remapping.
 */

beforeEach(function (): void {
    // Start from a known baseline — the global Pest beforeEach seeds the
    // default school but not its accounting catalogs.
    TaxRate::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();
});

/** Seed the default school with a parent/child pair plus a VAT rate. */
function seedDefaultAccountingCatalog(): array
{
    $parent = ChartOfAccount::factory()->asset()->create([
        'code' => '1100',
        'name' => 'Cash',
    ]);

    $child = ChartOfAccount::factory()->asset()->create([
        'code' => '1101',
        'name' => 'Cash on Hand',
        'parent_id' => $parent->getKey(),
    ]);

    $vatAccount = ChartOfAccount::factory()
        ->liability()
        ->system(ChartOfAccount::SYSTEM_VAT_OUTPUT)
        ->create(['code' => '2200', 'name' => 'Output VAT']);

    $vatRate = TaxRate::factory()->vatSales()->create([
        'account_id' => $vatAccount->getKey(),
    ]);

    TaxRate::factory()->exempt()->create(['account_id' => null]);

    return compact('parent', 'child', 'vatAccount', 'vatRate');
}

it('clones the chart of accounts onto a newly created school', function () {
    seedDefaultAccountingCatalog();
    $defaultCodes = ChartOfAccount::query()->pluck('code')->sort()->values()->all();

    $newSchool = School::factory()->create(['slug' => 'coa-clone-target']);
    $newSchool->makeCurrent();

    expect(ChartOfAccount::query()->pluck('code')->sort()->values()->all())
        ->toBe($defaultCodes);
});

it('carries the cash flag onto the cloned chart', function () {
    // SchoolObserver copies columns generically, so a new column rides along
    // without the observer changing. This pins that: a new school whose cash
    // accounts arrived unflagged would open the payment form to an empty
    // account picker.
    seedDefaultAccountingCatalog();
    ChartOfAccount::factory()->cashEquivalent()->create(['code' => '1110', 'name' => 'Cash in Bank']);

    $newSchool = School::factory()->create(['slug' => 'coa-cash-clone-target']);
    $newSchool->makeCurrent();

    expect(
        ChartOfAccount::query()->where('is_cash_equivalent', true)->orderBy('code')->pluck('code')->all()
    )->toBe(['1110']);
});

it('repoints a cloned account parent at the new school own row', function () {
    seedDefaultAccountingCatalog();

    $newSchool = School::factory()->create(['slug' => 'coa-parent-remap']);
    $newSchool->makeCurrent();

    $clonedChild = ChartOfAccount::query()->where('code', '1101')->firstOrFail();
    $clonedParent = ChartOfAccount::query()->where('code', '1100')->firstOrFail();

    // The child points at the NEW school's parent, not the default's.
    expect($clonedChild->parent_id)->toBe($clonedParent->getKey());

    // And that parent really does belong to the new school.
    expect($clonedParent->school_id)->toBe($newSchool->getKey());
});

it('never leaves a cloned account pointing across tenants', function () {
    seedDefaultAccountingCatalog();

    $newSchool = School::factory()->create(['slug' => 'coa-no-leak']);

    // Every account belonging to the new school whose parent_id is set must
    // resolve to a parent in the SAME school. Checked unscoped so a leak
    // cannot hide behind the global scope.
    $rows = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $newSchool->getKey())
        ->whereNotNull('parent_id')
        ->get();

    expect($rows)->not()->toBeEmpty();

    foreach ($rows as $row) {
        $parentSchoolId = ChartOfAccount::query()->withoutGlobalScopes()
            ->whereKey($row->parent_id)
            ->value('school_id');

        expect($parentSchoolId)->toBe($newSchool->getKey());
    }
});

it('repoints a cloned tax rate at the new school VAT account', function () {
    seedDefaultAccountingCatalog();

    $newSchool = School::factory()->create(['slug' => 'tax-account-remap']);
    $newSchool->makeCurrent();

    $clonedRate = TaxRate::query()->where('code', 'VAT_12_SALES')->firstOrFail();
    $clonedVatAccount = ChartOfAccount::query()->where('code', '2200')->firstOrFail();

    expect($clonedRate->account_id)->toBe($clonedVatAccount->getKey())
        ->and($clonedVatAccount->school_id)->toBe($newSchool->getKey());
});

it('preserves a null posting account on cloned exempt rates', function () {
    seedDefaultAccountingCatalog();

    $newSchool = School::factory()->create(['slug' => 'tax-null-account']);
    $newSchool->makeCurrent();

    expect(TaxRate::query()->where('code', 'VAT_EXEMPT')->value('account_id'))
        ->toBeNull();
});

it('does not clone the default school catalogs onto itself', function () {
    seedDefaultAccountingCatalog();

    $countBefore = ChartOfAccount::query()->count();

    // Re-firing the observer for the default school must be a no-op.
    (new SchoolObserver)->created(
        School::query()->where('slug', 'default')->firstOrFail()
    );

    expect(ChartOfAccount::query()->count())->toBe($countBefore);
});

it('is idempotent when the observer re-fires for the same school', function () {
    seedDefaultAccountingCatalog();

    $newSchool = School::factory()->create(['slug' => 'coa-idempotent']);

    $countAfterFirst = ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $newSchool->getKey())->count();
    $rateCountAfterFirst = TaxRate::query()->withoutGlobalScopes()
        ->where('school_id', $newSchool->getKey())->count();

    (new SchoolObserver)->created($newSchool);

    expect(ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $newSchool->getKey())->count())
        ->toBe($countAfterFirst);
    expect(TaxRate::query()->withoutGlobalScopes()
        ->where('school_id', $newSchool->getKey())->count())
        ->toBe($rateCountAfterFirst);
});

it('still clones the allowance and deduction-type catalogs alongside', function () {
    // Guard against the accounting additions regressing the original
    // flat-catalog clone that SchoolCatalogAutoCloneTest covers.
    Allowance::factory()->riceSubsidy()->create();
    seedDefaultAccountingCatalog();

    $newSchool = School::factory()->create(['slug' => 'coa-alongside']);
    $newSchool->makeCurrent();

    expect(Allowance::query()->count())->toBe(1)
        ->and(ChartOfAccount::query()->count())->toBeGreaterThan(0);
});
