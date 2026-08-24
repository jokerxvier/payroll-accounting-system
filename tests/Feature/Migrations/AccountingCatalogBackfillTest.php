<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use Illuminate\Support\Facades\DB;

/*
 * 2026_08_24_000004 — backfilling the accounting catalogs onto schools that
 * existed before Phase 5 Slice 1 shipped.
 *
 * SchoolObserver only fires on `created`, so pre-existing tenants held
 * allowances and deduction types but an empty chart of accounts. The
 * migration is the catch-up, and has to remap the same intra-set foreign
 * keys the observer does — otherwise the backfilled rows point at the
 * DEFAULT school's accounts.
 *
 * The migration itself already ran during RefreshDatabase (against an empty
 * DB, so it no-opped). These tests re-run its logic explicitly against a
 * populated one.
 */

function runAccountingBackfill(): void
{
    $path = database_path(
        'migrations/2026_08_24_000004_backfill_accounting_catalogs_for_existing_schools.php'
    );

    /** @var object{up: callable} $migration */
    $migration = require $path;
    $migration->up();
}

beforeEach(function (): void {
    TaxRate::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();
});

it('gives a pre-existing school the default chart and tax rates', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();

    // A school that predates Slice 1: it exists, but the observer never
    // cloned an accounting catalog onto it.
    $existing = School::factory()->create(['slug' => 'pre-existing']);
    ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $existing->getKey())->delete();
    TaxRate::query()->withoutGlobalScopes()
        ->where('school_id', $existing->getKey())->delete();

    // Seed the default school's catalog after the fact.
    $default->makeCurrent();
    $parent = ChartOfAccount::factory()->asset()->create(['code' => '1100']);
    ChartOfAccount::factory()->asset()->create([
        'code' => '1101',
        'parent_id' => $parent->getKey(),
    ]);
    $vatAccount = ChartOfAccount::factory()->liability()->create(['code' => '2200']);
    TaxRate::factory()->vatSales()->create(['account_id' => $vatAccount->getKey()]);
    TaxRate::factory()->exempt()->create(['account_id' => null]);

    runAccountingBackfill();

    $existing->makeCurrent();

    expect(ChartOfAccount::query()->pluck('code')->sort()->values()->all())
        ->toBe(['1100', '1101', '2200'])
        ->and(TaxRate::query()->count())->toBe(2);
});

it('remaps the parent and posting-account references onto the target school', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $existing = School::factory()->create(['slug' => 'remap-target']);
    ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $existing->getKey())->delete();
    TaxRate::query()->withoutGlobalScopes()
        ->where('school_id', $existing->getKey())->delete();

    $default->makeCurrent();
    $parent = ChartOfAccount::factory()->asset()->create(['code' => '1100']);
    ChartOfAccount::factory()->asset()->create([
        'code' => '1101',
        'parent_id' => $parent->getKey(),
    ]);
    $vatAccount = ChartOfAccount::factory()->liability()->create(['code' => '2200']);
    TaxRate::factory()->vatSales()->create(['account_id' => $vatAccount->getKey()]);

    runAccountingBackfill();

    $existing->makeCurrent();

    $child = ChartOfAccount::query()->where('code', '1101')->firstOrFail();
    $clonedParent = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
    $clonedVat = ChartOfAccount::query()->where('code', '2200')->firstOrFail();
    $rate = TaxRate::query()->where('code', 'VAT_12_SALES')->firstOrFail();

    // Everything points inside the target school, never back at the default.
    expect($child->parent_id)->toBe($clonedParent->getKey())
        ->and($clonedParent->school_id)->toBe($existing->getKey())
        ->and($rate->account_id)->toBe($clonedVat->getKey())
        ->and($clonedVat->school_id)->toBe($existing->getKey());

    // Belt and braces: no backfilled row references a foreign school's account.
    $foreign = DB::table('pas_tax_rates as t')
        ->join('pas_chart_of_accounts as a', 'a.id', '=', 't.account_id')
        ->whereColumn('t.school_id', '!=', 'a.school_id')
        ->count();

    expect($foreign)->toBe(0);
});

it('leaves a school that already has its own chart untouched', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $customised = School::factory()->create(['slug' => 'already-customised']);

    $default->makeCurrent();
    ChartOfAccount::factory()->asset()->create(['code' => '1100']);

    // The tenant has started its own chart — the backfill must not touch it.
    $customised->makeCurrent();
    ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $customised->getKey())->delete();
    ChartOfAccount::factory()->income()->create(['code' => '9999']);

    runAccountingBackfill();

    $customised->makeCurrent();

    expect(ChartOfAccount::query()->pluck('code')->all())->toBe(['9999']);
});

it('is idempotent across repeated runs', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $existing = School::factory()->create(['slug' => 'idempotent-target']);
    ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $existing->getKey())->delete();
    TaxRate::query()->withoutGlobalScopes()
        ->where('school_id', $existing->getKey())->delete();

    $default->makeCurrent();
    ChartOfAccount::factory()->asset()->create(['code' => '1100']);

    runAccountingBackfill();
    runAccountingBackfill();
    runAccountingBackfill();

    $existing->makeCurrent();

    expect(ChartOfAccount::query()->count())->toBe(1);
});

it('no-ops when the default school has no catalog to clone', function (): void {
    $existing = School::factory()->create(['slug' => 'nothing-to-clone']);
    ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $existing->getKey())->delete();

    runAccountingBackfill();

    $existing->makeCurrent();

    expect(ChartOfAccount::query()->count())->toBe(0);
});
