<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\School;

/*
 * 2026_08_25_000007 — flagging the accounts that hold cash on charts that
 * already existed when Slice 8b shipped.
 *
 * The column defaults to false, so without the backfill every existing school
 * would open the payment form to an empty account picker. The migration ran
 * during RefreshDatabase against an empty table and no-opped; these tests
 * re-run its UPDATE against a populated one.
 */

function runCashEquivalentBackfill(): void
{
    $path = database_path(
        'migrations/2026_08_25_000007_add_is_cash_equivalent_to_pas_chart_of_accounts_table.php'
    );

    /** @var object{up: callable} $migration */
    $migration = require $path;
    $migration->up();
}

function unflaggedAccount(string $code, string $type = ChartOfAccount::TYPE_ASSET): ChartOfAccount
{
    return ChartOfAccount::factory()->ofType($type)->create([
        'code' => $code,
        'is_cash_equivalent' => false,
    ]);
}

it('flags the two seeded cash accounts and nothing else', function () {
    $cashOnHand = unflaggedAccount('1100');
    $cashInBank = unflaggedAccount('1110');
    $prepaid = unflaggedAccount('1400');
    $ppe = unflaggedAccount('1510');
    $receivable = unflaggedAccount('1200');

    runCashEquivalentBackfill();

    expect($cashOnHand->fresh()->is_cash_equivalent)->toBeTrue()
        ->and($cashInBank->fresh()->is_cash_equivalent)->toBeTrue()
        // The three the old "any asset without a system_code" approximation
        // wrongly admitted, or that were never cash to begin with.
        ->and($prepaid->fresh()->is_cash_equivalent)->toBeFalse()
        ->and($ppe->fresh()->is_cash_equivalent)->toBeFalse()
        ->and($receivable->fresh()->is_cash_equivalent)->toBeFalse();
});

it('leaves a reused code alone when the account is not an asset', function () {
    // A school that renumbered its chart and happens to use 1100 for
    // something else entirely. Flagging it would put a liability in the
    // payment form's picker.
    $notCash = unflaggedAccount('1100', ChartOfAccount::TYPE_LIABILITY);

    runCashEquivalentBackfill();

    expect($notCash->fresh()->is_cash_equivalent)->toBeFalse();
});

it('flags the cash accounts of every school, not just the current tenant', function () {
    $other = School::factory()->create();

    $mine = unflaggedAccount('1100');
    $theirs = ChartOfAccount::factory()->asset()->create([
        'school_id' => $other->getKey(),
        'code' => '1100',
        'is_cash_equivalent' => false,
    ]);

    runCashEquivalentBackfill();

    expect($mine->fresh()->is_cash_equivalent)->toBeTrue()
        ->and(
            ChartOfAccount::query()->withoutGlobalScopes()
                ->whereKey($theirs->getKey())->value('is_cash_equivalent')
        )->toEqual(true);
});

it('is idempotent and does not unflag a hand-marked account', function () {
    $custom = ChartOfAccount::factory()->cashEquivalent()->create(['code' => '1120']);

    runCashEquivalentBackfill();
    runCashEquivalentBackfill();

    // The UPDATE only ever sets true, so a school that marked its own petty
    // cash account keeps it.
    expect($custom->fresh()->is_cash_equivalent)->toBeTrue();
});
