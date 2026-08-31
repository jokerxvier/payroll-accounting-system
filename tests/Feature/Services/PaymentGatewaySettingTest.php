<?php

declare(strict_types=1);

use App\Models\Pas\AuditLog;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\PaymentGatewaySetting;
use App\Models\Pas\School;
use Illuminate\Support\Facades\DB;

/*
 * Gateway credentials — the safety properties, pinned before anything is
 * built on top of them.
 *
 * A leaked secret key moves money, so three things have to hold and keep
 * holding:
 *
 *   - the secret is ciphertext at rest, not just "not displayed"
 *   - it never reaches pas_audit_logs, which auditors export
 *   - a half-configured row is savable but not usable
 */

beforeEach(function (): void {
    PaymentGatewaySetting::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();
    AuditLog::query()->withoutGlobalScopes()->delete();
});

/**
 * The two accounts a gateway resolves to when nobody overrides them.
 *
 * `1110` by chart code from config; `5250` by the MERCHANT_FEES system code.
 * They resolve by different mechanisms on purpose — see
 * GatewayAccountResolver.
 */
function seedGatewayDefaults(): void
{
    ChartOfAccount::factory()->asset()->cashEquivalent()->create(['code' => '1110']);
    ChartOfAccount::factory()->expense()
        ->system(ChartOfAccount::SYSTEM_MERCHANT_FEES)
        ->create(['code' => '5250']);
}

it('stores the secret as ciphertext, not plain text', function (): void {
    $setting = PaymentGatewaySetting::factory()->create([
        'secret_key' => 'sk_test_supersecret',
        'webhook_secret' => 'whsec_alsosecret',
    ]);

    $raw = DB::table('pas_payment_gateway_settings')
        ->where('id', $setting->getKey())
        ->first();

    expect($raw->secret_key)->not->toBe('sk_test_supersecret')
        ->and($raw->secret_key)->not->toContain('supersecret')
        ->and($raw->webhook_secret)->not->toContain('alsosecret')
        // …and still round-trips through the cast.
        ->and($setting->fresh()->secret_key)->toBe('sk_test_supersecret')
        ->and($setting->fresh()->webhook_secret)->toBe('whsec_alsosecret');
});

it('keeps both secrets out of the audit trail entirely', function (): void {
    $setting = PaymentGatewaySetting::factory()->create([
        'secret_key' => 'sk_test_supersecret',
        'webhook_secret' => 'whsec_alsosecret',
    ]);

    $setting->forceFill(['secret_key' => 'sk_test_rotated'])->save();

    $rows = AuditLog::query()->withoutGlobalScopes()->get();
    $dumped = $rows->map(fn (AuditLog $log): string => json_encode([
        $log->before,
        $log->after,
    ]) ?: '')->implode(' ');

    // The change is recorded; the values are not.
    expect($rows)->not->toBeEmpty()
        ->and($dumped)->not->toContain('supersecret')
        ->and($dumped)->not->toContain('alsosecret')
        ->and($dumped)->not->toContain('sk_test_rotated')
        ->and($dumped)->not->toContain('secret_key')
        ->and($dumped)->not->toContain('webhook_secret');
});

it('masks a secret down to four characters for the admin screen', function (): void {
    $setting = PaymentGatewaySetting::factory()->create([
        'secret_key' => 'sk_test_abcdefgh4242',
    ]);

    expect($setting->maskedSecret())->toBe('••••4242');
});

it('reports no mask when no secret is set', function (): void {
    $setting = PaymentGatewaySetting::factory()->create(['secret_key' => null]);

    expect($setting->maskedSecret())->toBeNull();
});

it('refuses a row that is switched off or missing a webhook secret', function (): void {
    seedGatewayDefaults();
    $cash = ChartOfAccount::query()->where('code', '1110')->sole();
    $fee = ChartOfAccount::query()->where('code', '5250')->sole();

    $inactive = PaymentGatewaySetting::factory()
        ->stripe()
        ->usable($cash, $fee)
        ->create(['is_active' => false]);
    expect($inactive->isUsable())->toBeFalse();

    // An unverifiable webhook is an open endpoint that marks invoices paid,
    // so this must never count as ready.
    $noWebhookSecret = PaymentGatewaySetting::factory()
        ->stripe()
        ->live()
        ->usable($cash, $fee)
        ->create(['webhook_secret' => null]);
    expect($noWebhookSecret->isUsable())->toBeFalse();
});

it('is usable with no accounts chosen at all', function (): void {
    // The whole point of the change: an operator pastes API keys and switches
    // it on. The accounts resolve to the school's defaults.
    seedGatewayDefaults();

    $setting = PaymentGatewaySetting::factory()->create([
        'is_active' => true,
        'cash_account_id' => null,
        'fee_account_id' => null,
    ]);

    expect($setting->isUsable())->toBeTrue();
});

it('is NOT usable when the default fee account is missing', function (): void {
    // The regression this design had to avoid. Without it, a school with a
    // broken chart would still show a Pay button and the failure would land
    // in the webhook — after the customer had already paid.
    ChartOfAccount::factory()->asset()->cashEquivalent()->create(['code' => '1110']);

    $setting = PaymentGatewaySetting::factory()->create([
        'is_active' => true,
        'cash_account_id' => null,
        'fee_account_id' => null,
    ]);

    expect($setting->isUsable())->toBeFalse();
});

it('is NOT usable when the default cash account is missing', function (): void {
    ChartOfAccount::factory()->expense()
        ->system(ChartOfAccount::SYSTEM_MERCHANT_FEES)
        ->create(['code' => '5250']);

    $setting = PaymentGatewaySetting::factory()->create([
        'is_active' => true,
        'cash_account_id' => null,
        'fee_account_id' => null,
    ]);

    expect($setting->isUsable())->toBeFalse();
});

it('accepts a fully configured active row', function (): void {
    seedGatewayDefaults();
    $cash = ChartOfAccount::query()->where('code', '1110')->sole();
    $fee = ChartOfAccount::query()->where('code', '5250')->sole();

    $setting = PaymentGatewaySetting::factory()->usable($cash, $fee)->create();

    expect($setting->isUsable())->toBeTrue();
});

it('keeps test and live as separate rows for the same provider', function (): void {
    PaymentGatewaySetting::factory()->create();
    PaymentGatewaySetting::factory()->live()->create();

    expect(PaymentGatewaySetting::query()->count())->toBe(2);
});

it('never lets one school read another school\'s credentials', function (): void {
    $other = School::factory()->create();
    PaymentGatewaySetting::factory()->create(['school_id' => $other->getKey()]);
    PaymentGatewaySetting::factory()->stripe()->create();

    // The tenant scope is the only thing between two schools' secret keys.
    expect(PaymentGatewaySetting::query()->count())->toBe(1)
        ->and(PaymentGatewaySetting::query()->sole()->provider)
        ->toBe(PaymentGatewaySetting::PROVIDER_STRIPE);
});
