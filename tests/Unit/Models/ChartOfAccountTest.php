<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;

/*
 * ChartOfAccount — the normal-balance rule and the signed-movement helper.
 *
 * These two behaviours are load-bearing for every financial report in
 * Slice 8. The client's requirements doc states the General Ledger formula
 * as `Ending = Opening + Debits - Credits`, which is true only for
 * debit-normal accounts; applying it to a liability, equity, or income
 * account reports the balance with the wrong sign. The `movementCentavos()`
 * tests below pin BOTH directions so that bug cannot be reintroduced.
 */

it('maps asset and expense accounts to a debit normal balance', function (string $type) {
    expect(ChartOfAccount::normalBalanceForType($type))
        ->toBe(ChartOfAccount::BALANCE_DEBIT);
})->with([
    ChartOfAccount::TYPE_ASSET,
    ChartOfAccount::TYPE_EXPENSE,
]);

it('maps liability, equity, and income accounts to a credit normal balance', function (string $type) {
    expect(ChartOfAccount::normalBalanceForType($type))
        ->toBe(ChartOfAccount::BALANCE_CREDIT);
})->with([
    ChartOfAccount::TYPE_LIABILITY,
    ChartOfAccount::TYPE_EQUITY,
    ChartOfAccount::TYPE_INCOME,
]);

it('rejects an unknown account type rather than guessing a normal balance', function () {
    expect(fn () => ChartOfAccount::normalBalanceForType('liabilty'))
        ->toThrow(InvalidArgumentException::class);
});

it('covers every declared type in the normal-balance mapping', function () {
    // Guards against someone adding a sixth type to TYPES without teaching
    // normalBalanceForType() about it — the match would throw at runtime.
    foreach (ChartOfAccount::TYPES as $type) {
        expect(ChartOfAccount::normalBalanceForType($type))
            ->toBeIn(ChartOfAccount::NORMAL_BALANCES);
    }
});

it('increases a debit-normal account on debits and decreases it on credits', function () {
    $cash = ChartOfAccount::factory()->asset()->make();

    // 5,000.00 debited, 2,000.00 credited → +3,000.00 for an asset.
    expect($cash->movementCentavos(500_000, 200_000))->toBe(300_000);
    expect($cash->movementCentavos(0, 200_000))->toBe(-200_000);
    expect($cash->movementCentavos(200_000, 200_000))->toBe(0);
});

it('increases a credit-normal account on credits and decreases it on debits', function (string $type) {
    $account = ChartOfAccount::factory()->ofType($type)->make();

    // Same figures as the debit-normal case, opposite sign. This is exactly
    // the case the doc's single formula gets wrong.
    expect($account->movementCentavos(500_000, 200_000))->toBe(-300_000);
    expect($account->movementCentavos(0, 200_000))->toBe(200_000);
    expect($account->movementCentavos(200_000, 200_000))->toBe(0);
})->with([
    ChartOfAccount::TYPE_LIABILITY,
    ChartOfAccount::TYPE_EQUITY,
    ChartOfAccount::TYPE_INCOME,
]);

it('reports a debit-normal account through isDebitNormal', function () {
    expect(ChartOfAccount::factory()->asset()->make()->isDebitNormal())->toBeTrue();
    expect(ChartOfAccount::factory()->expense()->make()->isDebitNormal())->toBeTrue();
    expect(ChartOfAccount::factory()->income()->make()->isDebitNormal())->toBeFalse();
    expect(ChartOfAccount::factory()->liability()->make()->isDebitNormal())->toBeFalse();
});

it('keeps the factory normal_balance consistent with the account type', function (string $type) {
    $account = ChartOfAccount::factory()->ofType($type)->make();

    expect($account->normal_balance)
        ->toBe(ChartOfAccount::normalBalanceForType($type));
})->with(ChartOfAccount::TYPES);
