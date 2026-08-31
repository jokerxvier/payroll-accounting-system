<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\PaymentGatewaySetting;
use App\Models\User;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * /admin/payment-gateways — where a school enters its own credentials.
 *
 * The property this file exists to protect: **a secret key must never reach
 * the browser.** Inertia props are serialised into the page source, so a
 * screen that pre-filled the stored key would publish it to anyone who
 * pressed View Source. Everything else here follows from that — the masked
 * display, and the blank-means-unchanged rule that makes editing possible
 * without ever sending the value back.
 */

beforeEach(function (): void {
    ChartOfAccount::query()->withoutGlobalScopes()->delete();
    PaymentGatewaySetting::query()->withoutGlobalScopes()->delete();

    $this->seed(AccountingCatalogSeeder::class);

    $this->cash = ChartOfAccount::query()->where('code', '1110')->firstOrFail();
    $this->feeAccount = ChartOfAccount::query()->where('code', '5900')->firstOrFail();
});

function gatewayAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gatewayPayload(array $overrides = []): array
{
    return [
        'provider' => 'paymongo',
        'mode' => 'test',
        'publishable_key' => 'pk_test_visible',
        'secret_key' => 'sk_test_topsecret',
        'webhook_secret' => 'whsec_topsecret',
        'cash_account_id' => test()->cash->getKey(),
        'fee_account_id' => test()->feeAccount->getKey(),
        'is_active' => true,
        ...$overrides,
    ];
}

/* ── The gate ───────────────────────────────────────────────────────── */

it('lets a super admin in', function (): void {
    $this->actingAs(gatewayAuthAs('super-admin'))
        ->get(route('admin.payment-gateways.index'))
        ->assertOk();
});

it('refuses an accountant, who may post the ledger but not hold the keys', function (): void {
    $this->actingAs(gatewayAuthAs('accountant'))
        ->get(route('admin.payment-gateways.index'))
        ->assertForbidden();
});

it('refuses a payroll officer and an auditor', function (string $role): void {
    $this->actingAs(gatewayAuthAs($role))
        ->get(route('admin.payment-gateways.index'))
        ->assertForbidden();
})->with(['payroll-officer', 'auditor']);

/* ── The secret never travels ───────────────────────────────────────── */

it('never sends a stored secret to the browser', function (): void {
    PaymentGatewaySetting::factory()->create([
        'secret_key' => 'sk_test_topsecret',
        'webhook_secret' => 'whsec_topsecret',
    ]);

    $response = $this->actingAs(gatewayAuthAs('super-admin'))
        ->get(route('admin.payment-gateways.index'))
        ->assertOk();

    // The whole page source, not just the props we thought to check.
    expect($response->getContent())
        ->not->toContain('sk_test_topsecret')
        ->not->toContain('whsec_topsecret');

    $response->assertInertia(fn ($page) => $page
        ->component('admin/accounting/payment-gateways/index', false)
        ->where('settings.0.secret_masked', '••••cret')
        ->where('settings.0.has_secret', true)
        ->where('settings.0.has_webhook_secret', true));
});

/* ── Saving ─────────────────────────────────────────────────────────── */

it('stores credentials and reports the row usable', function (): void {
    $this->actingAs(gatewayAuthAs('super-admin'))
        ->post(route('admin.payment-gateways.store'), gatewayPayload())
        ->assertSessionHasNoErrors();

    $setting = PaymentGatewaySetting::query()->sole();

    expect($setting->secret_key)->toBe('sk_test_topsecret')
        ->and($setting->isUsable())->toBeTrue();
});

it('leaves a stored secret alone when the field is submitted blank', function (): void {
    $user = gatewayAuthAs('super-admin');

    $this->actingAs($user)->post(route('admin.payment-gateways.store'), gatewayPayload());

    // Editing the account pickers without retyping the key must not wipe it.
    $this->actingAs($user)->post(route('admin.payment-gateways.store'), gatewayPayload([
        'secret_key' => '',
        'webhook_secret' => '',
        'publishable_key' => 'pk_test_changed',
    ]))->assertSessionHasNoErrors();

    $setting = PaymentGatewaySetting::query()->sole();

    expect($setting->secret_key)->toBe('sk_test_topsecret')
        ->and($setting->webhook_secret)->toBe('whsec_topsecret')
        ->and($setting->publishable_key)->toBe('pk_test_changed');
});

it('refuses to switch a gateway on without a webhook signing secret', function (): void {
    $this->actingAs(gatewayAuthAs('super-admin'))
        ->post(route('admin.payment-gateways.store'), gatewayPayload([
            'webhook_secret' => '',
            'is_active' => true,
        ]))
        // Without it a delivery cannot be verified, which makes the webhook
        // an open endpoint that marks invoices paid.
        ->assertSessionHasErrors('webhook_secret');
});

it('activates with no accounts chosen at all', function (): void {
    // The point of the change: pasting keys and switching on is the whole
    // interaction. The accounts resolve to the school's defaults.
    $this->actingAs(gatewayAuthAs('super-admin'))
        ->post(route('admin.payment-gateways.store'), gatewayPayload([
            'cash_account_id' => null,
            'fee_account_id' => null,
        ]))
        ->assertSessionHasNoErrors();

    $setting = PaymentGatewaySetting::query()->sole();

    expect($setting->cash_account_id)->toBeNull()
        ->and($setting->fee_account_id)->toBeNull()
        ->and($setting->isUsable())->toBeTrue();
});

it('still refuses to switch on without a webhook secret', function (): void {
    $this->actingAs(gatewayAuthAs('super-admin'))
        ->post(route('admin.payment-gateways.store'), gatewayPayload([
            'webhook_secret' => '',
            'is_active' => true,
        ]))
        ->assertSessionHasErrors('webhook_secret');
});

it('names the resolved defaults on the page', function (): void {
    $this->actingAs(gatewayAuthAs('super-admin'))
        ->get(route('admin.payment-gateways.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('defaults.cash.code', '1110')
            ->where('defaults.fee.code', '5250'));
});

it('refuses a cash account that is not a cash account', function (): void {
    $prepaid = ChartOfAccount::query()->where('code', '1400')->firstOrFail();

    $this->actingAs(gatewayAuthAs('super-admin'))
        ->post(route('admin.payment-gateways.store'), gatewayPayload([
            'cash_account_id' => $prepaid->getKey(),
        ]))
        ->assertSessionHasErrors('cash_account_id');
});

it('refuses a fee account that is not an expense', function (): void {
    $this->actingAs(gatewayAuthAs('super-admin'))
        ->post(route('admin.payment-gateways.store'), gatewayPayload([
            'fee_account_id' => $this->cash->getKey(),
        ]))
        ->assertSessionHasErrors('fee_account_id');
});

it('switches the other mode off when one is activated', function (): void {
    $user = gatewayAuthAs('super-admin');

    $this->actingAs($user)->post(route('admin.payment-gateways.store'), gatewayPayload());
    $this->actingAs($user)->post(route('admin.payment-gateways.store'), gatewayPayload([
        'mode' => 'live',
        'secret_key' => 'sk_live_realmoney',
    ]));

    // A school takes money in test mode or in live mode, never both.
    $active = PaymentGatewaySetting::query()->active()->get();

    expect($active)->toHaveCount(1)
        ->and($active->first()->mode)->toBe('live');
});
