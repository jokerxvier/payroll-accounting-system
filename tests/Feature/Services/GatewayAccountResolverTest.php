<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentGatewaySetting;
use App\Services\Payments\GatewayAccountResolver;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * Which accounts a gateway receipt posts through.
 *
 * The two sides resolve by different mechanisms, and the reason is worth
 * pinning: the fee account carries a `system_code`, while the cash account is
 * found by chart CODE from config. Giving a cash account a system code would
 * remove it from the manual payment picker, which excludes system accounts on
 * purpose — so the asymmetry is load-bearing, not an oversight.
 */

beforeEach(function (): void {
    ChartOfAccount::query()->withoutGlobalScopes()->delete();
    $this->seed(AccountingCatalogSeeder::class);
});

function gatewayAccounts(): GatewayAccountResolver
{
    return app(GatewayAccountResolver::class);
}

it('defaults settled money to Cash in Bank', function (): void {
    $account = gatewayAccounts()->resolveCash(new PaymentGatewaySetting);

    expect($account->code)->toBe('1110')
        ->and($account->is_cash_equivalent)->toBeTrue();
});

it('defaults the fee to the merchant fees system account', function (): void {
    $account = gatewayAccounts()->resolveFee(new PaymentGatewaySetting);

    expect($account->code)->toBe('5250')
        ->and($account->system_code)->toBe(ChartOfAccount::SYSTEM_MERCHANT_FEES)
        ->and($account->type)->toBe(ChartOfAccount::TYPE_EXPENSE)
        // Locked, so nobody can delete the account posting depends on.
        ->and($account->is_locked)->toBeTrue();
});

it('lets a school override either side', function (): void {
    $otherCash = ChartOfAccount::query()->where('code', '1100')->sole();
    $otherFee = ChartOfAccount::query()->where('code', '5900')->sole();

    $setting = PaymentGatewaySetting::factory()->make([
        'cash_account_id' => $otherCash->getKey(),
        'fee_account_id' => $otherFee->getKey(),
    ]);

    expect(gatewayAccounts()->resolveCash($setting)->code)->toBe('1100')
        ->and(gatewayAccounts()->resolveFee($setting)->code)->toBe('5900');
});

it('falls back rather than throwing when an override points at nothing', function (): void {
    // A stale id is a configuration wrinkle, not a reason to refuse money
    // that has already arrived — the same soft failure ControlAccountResolver
    // allows.
    $setting = PaymentGatewaySetting::factory()->make([
        'cash_account_id' => 999_999,
        'fee_account_id' => 999_999,
    ]);

    expect(gatewayAccounts()->resolveCash($setting)->code)->toBe('1110')
        ->and(gatewayAccounts()->resolveFee($setting)->code)->toBe('5250');
});

it('refuses rather than substituting when the fee account is gone', function (): void {
    ChartOfAccount::query()->where('code', '5250')->delete();

    gatewayAccounts()->resolveFee(new PaymentGatewaySetting);
})->throws(RuntimeException::class);

it('refuses rather than substituting when the cash account is gone', function (): void {
    ChartOfAccount::query()->where('code', '1110')->delete();

    // Posting real money into a substitute would put it somewhere nobody
    // chose, so this throws instead of picking the nearest cash account.
    gatewayAccounts()->resolveCash(new PaymentGatewaySetting);
})->throws(RuntimeException::class);

/* ── The trap this design exists to avoid ───────────────────────────── */

it('keeps Cash in Bank in the manual payment picker', function (): void {
    // If 1110 had been given a system_code to make it the gateway default, it
    // would silently vanish from here — PaymentController::cashAccountOptions()
    // excludes system accounts, and hand-keying a receipt would lose the bank.
    $options = ChartOfAccount::query()
        ->active()
        ->cashEquivalent()
        ->whereNull('system_code')
        ->pluck('code')
        ->all();

    expect($options)->toContain('1110')
        ->and($options)->toContain('1100');
});

it('never offers the merchant fee account as a place to receive money', function (): void {
    $cashOptions = ChartOfAccount::query()->cashEquivalent()->pluck('code')->all();

    expect($cashOptions)->not->toContain('5250');
});

/* ── Snapshotting ───────────────────────────────────────────────────── */

it('is what a gateway payment records, so a repost reproduces', function (): void {
    $setting = PaymentGatewaySetting::factory()->create([
        'cash_account_id' => null,
        'fee_account_id' => null,
    ]);

    $cashId = gatewayAccounts()->resolveCash($setting)->getKey();
    $feeId = gatewayAccounts()->resolveFee($setting)->getKey();

    // A payment stores the RESOLVED ids. Changing the settings afterwards
    // must not change what an already-posted entry would reproduce.
    $payment = Payment::factory()->receipt()->create([
        'cash_account_id' => $cashId,
        'fee_account_id' => $feeId,
        'fee_centavos' => 2_800,
    ]);

    $setting->forceFill([
        'cash_account_id' => ChartOfAccount::query()->where('code', '1100')->value('id'),
    ])->save();

    expect($payment->refresh()->cash_account_id)->toBe($cashId);
});
