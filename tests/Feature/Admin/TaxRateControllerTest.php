<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use App\Models\User;

/*
 * /admin/tax-rates resource controller (Phase 5 Slice 1).
 *
 * Pinned behaviours:
 *  - Role gates match App\Policies\Pas\AccountingRoles.
 *  - Rates are submitted and stored in basis points, never as a float.
 *  - A VAT rate that charges tax must name the account it posts to;
 *    without one an invoice using it could not produce a balanced entry.
 *  - Exempt and zero-rated rates must be 0%, and stay distinct types.
 *  - The posting account must belong to the same school.
 */

function taxRateAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function vatOutputAccount(): ChartOfAccount
{
    return ChartOfAccount::factory()
        ->liability()
        ->system(ChartOfAccount::SYSTEM_VAT_OUTPUT)
        ->create(['code' => '2200', 'name' => 'Output VAT']);
}

/** @return array<string, mixed> */
function validTaxRatePayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'VAT_12_SALES',
        'name' => 'VAT 12% (Sales)',
        'rate_bps' => 1200,
        'type' => TaxRate::TYPE_VAT_SALES,
        'account_id' => null,
        'is_active' => true,
    ], $overrides);
}

it('lets every manage role view the index', function (string $role) {
    TaxRate::factory()->vatSales()->create();

    $this->actingAs(taxRateAuthAs($role))
        ->get('/admin/tax-rates')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/accounting/tax-rates/index', false)
                ->has('taxRates', 1),
        );
})->with(['super-admin', 'accountant', 'payroll-officer']);

it('lets an auditor read but not mutate', function () {
    $auditor = taxRateAuthAs('auditor');
    $account = vatOutputAccount();

    $this->actingAs($auditor)->get('/admin/tax-rates')->assertOk();

    // The payload must be valid to reach the Gate: FormRequest validation
    // runs before the controller's Gate::authorize, so an invalid body would
    // redirect with validation errors and never exercise authorization.
    $this->actingAs($auditor)
        ->post('/admin/tax-rates', validTaxRatePayload([
            'account_id' => $account->getKey(),
        ]))
        ->assertForbidden();
});

it('locks an ordinary employee out', function () {
    $this->actingAs(taxRateAuthAs('employee'))
        ->get('/admin/tax-rates')
        ->assertForbidden();
});

it('requires authentication', function () {
    $this->get('/admin/tax-rates')->assertRedirect('/login');
});

it('stores the rate in basis points', function () {
    $account = vatOutputAccount();

    $this->actingAs(taxRateAuthAs('accountant'))
        ->post('/admin/tax-rates', validTaxRatePayload([
            'account_id' => $account->getKey(),
        ]))
        ->assertRedirect('/admin/tax-rates');

    // 12% is persisted as the integer 1200 — never 0.12, never a float.
    expect(TaxRate::query()->where('code', 'VAT_12_SALES')->value('rate_bps'))
        ->toBe(1200);
});

it('rejects a VAT rate that charges tax but names no account', function () {
    $this->actingAs(taxRateAuthAs('accountant'))
        ->post('/admin/tax-rates', validTaxRatePayload(['account_id' => null]))
        ->assertSessionHasErrors('account_id');
});

it('accepts a zero-rate VAT row without an account', function () {
    // A 0% VAT-typed rate posts nothing, so it needs no account.
    $this->actingAs(taxRateAuthAs('accountant'))
        ->post('/admin/tax-rates', validTaxRatePayload([
            'code' => 'VAT_0_SALES',
            'rate_bps' => 0,
            'account_id' => null,
        ]))
        ->assertSessionHasNoErrors();
});

it('rejects an exempt or zero-rated row carrying a non-zero rate', function (string $type) {
    $this->actingAs(taxRateAuthAs('accountant'))
        ->post('/admin/tax-rates', validTaxRatePayload([
            'code' => 'BAD_'.$type,
            'type' => $type,
            'rate_bps' => 1200,
        ]))
        ->assertSessionHasErrors('rate_bps');
})->with([TaxRate::TYPE_EXEMPT, TaxRate::TYPE_ZERO_RATED]);

it('accepts exempt and zero-rated rows at zero', function (string $type) {
    $this->actingAs(taxRateAuthAs('accountant'))
        ->post('/admin/tax-rates', validTaxRatePayload([
            'code' => 'OK_'.$type,
            'type' => $type,
            'rate_bps' => 0,
            'account_id' => null,
        ]))
        ->assertSessionHasNoErrors();

    expect(TaxRate::query()->where('code', 'OK_'.$type)->value('type'))->toBe($type);
})->with([TaxRate::TYPE_EXEMPT, TaxRate::TYPE_ZERO_RATED]);

it('rejects a rate above 100 percent', function () {
    $account = vatOutputAccount();

    $this->actingAs(taxRateAuthAs('accountant'))
        ->post('/admin/tax-rates', validTaxRatePayload([
            'rate_bps' => 10_001,
            'account_id' => $account->getKey(),
        ]))
        ->assertSessionHasErrors('rate_bps');
});

it('rejects a posting account from another school', function () {
    $other = School::factory()->create(['slug' => 'tax-foreign-account']);
    $foreignAccount = ChartOfAccount::query()->withoutGlobalScopes()->create([
        'school_id' => $other->getKey(),
        'code' => '2299',
        'name' => 'Foreign VAT',
        'type' => ChartOfAccount::TYPE_LIABILITY,
        'normal_balance' => ChartOfAccount::BALANCE_CREDIT,
        'cash_flow_category' => ChartOfAccount::CASH_FLOW_OPERATING,
        'is_active' => true,
        'is_locked' => false,
    ]);

    $this->actingAs(taxRateAuthAs('accountant'))
        ->post('/admin/tax-rates', validTaxRatePayload([
            'account_id' => $foreignAccount->getKey(),
        ]))
        ->assertSessionHasErrors('account_id');
});

it('rejects a duplicate code within the same school', function () {
    $account = vatOutputAccount();
    TaxRate::factory()->vatSales()->create(['account_id' => $account->getKey()]);

    $this->actingAs(taxRateAuthAs('accountant'))
        ->post('/admin/tax-rates', validTaxRatePayload([
            'account_id' => $account->getKey(),
        ]))
        ->assertSessionHasErrors('code');
});

it('updates a rate without tripping its own unique rule', function () {
    $account = vatOutputAccount();
    $rate = TaxRate::factory()->vatSales()->create([
        'account_id' => $account->getKey(),
    ]);

    $this->actingAs(taxRateAuthAs('accountant'))
        ->patch("/admin/tax-rates/{$rate->getKey()}", validTaxRatePayload([
            'code' => $rate->code,
            'name' => 'VAT 12% Output',
            'account_id' => $account->getKey(),
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/tax-rates');

    expect($rate->fresh()->name)->toBe('VAT 12% Output');
});

it('deletes a tax rate', function () {
    $account = vatOutputAccount();
    $rate = TaxRate::factory()->vatSales()->create([
        'account_id' => $account->getKey(),
    ]);

    $this->actingAs(taxRateAuthAs('accountant'))
        ->delete("/admin/tax-rates/{$rate->getKey()}")
        ->assertRedirect('/admin/tax-rates')
        ->assertSessionHas('success');

    expect(TaxRate::query()->whereKey($rate->getKey())->exists())->toBeFalse();
});
