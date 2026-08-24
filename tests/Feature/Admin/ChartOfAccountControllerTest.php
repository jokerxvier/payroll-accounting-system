<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\School;
use App\Models\User;

/*
 * /admin/chart-of-accounts resource controller (Phase 5 Slice 1).
 *
 * Pinned behaviours:
 *  - Role gates match App\Policies\Pas\AccountingRoles: manage roles get
 *    full CRUD, auditor is read-only, employee is locked out entirely.
 *  - `normal_balance` is derived server-side from `type` and cannot be
 *    overridden by the client — a credit-normal asset would corrupt every
 *    report that reads the column.
 *  - System (`is_locked`) accounts cannot be deleted, re-coded, or retyped,
 *    but can still be renamed and re-filed.
 *  - A parent account must belong to the same school, and an account cannot
 *    parent itself.
 *  - destroy() soft-blocks on sub-accounts and on tax rates that post to it,
 *    rather than surfacing a raw FK error.
 */

function coaAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/** @return array<string, mixed> */
function validAccountPayload(array $overrides = []): array
{
    return array_merge([
        'code' => '6100',
        'name' => 'Unit-test Account',
        'type' => ChartOfAccount::TYPE_EXPENSE,
        'subtype' => 'operating_expense',
        'cash_flow_category' => ChartOfAccount::CASH_FLOW_OPERATING,
        'parent_id' => null,
        'description' => null,
        'is_active' => true,
    ], $overrides);
}

it('lets every manage role view the index', function (string $role) {
    ChartOfAccount::factory()->asset()->create(['code' => '1100']);
    ChartOfAccount::factory()->income()->create(['code' => '4100']);

    $this->actingAs(coaAuthAs($role))
        ->get('/admin/chart-of-accounts')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/accounting/chart-of-accounts/index', false)
                ->has('accounts', 2),
        );
})->with(['super-admin', 'accountant', 'payroll-officer']);

it('lets an auditor read the index but not create', function () {
    $auditor = coaAuthAs('auditor');

    $this->actingAs($auditor)->get('/admin/chart-of-accounts')->assertOk();
    $this->actingAs($auditor)->get('/admin/chart-of-accounts/create')->assertForbidden();
    $this->actingAs($auditor)
        ->post('/admin/chart-of-accounts', validAccountPayload())
        ->assertForbidden();
});

it('locks an ordinary employee out of the chart of accounts entirely', function () {
    $employee = coaAuthAs('employee');

    $this->actingAs($employee)->get('/admin/chart-of-accounts')->assertForbidden();
    $this->actingAs($employee)->get('/admin/chart-of-accounts/create')->assertForbidden();
});

it('requires authentication', function () {
    $this->get('/admin/chart-of-accounts')->assertRedirect('/login');
});

it('creates an account and derives its normal balance from the type', function () {
    $this->actingAs(coaAuthAs('accountant'))
        ->post('/admin/chart-of-accounts', validAccountPayload([
            'code' => '4300',
            'name' => 'Canteen Income',
            'type' => ChartOfAccount::TYPE_INCOME,
        ]))
        ->assertRedirect('/admin/chart-of-accounts');

    $account = ChartOfAccount::query()->where('code', '4300')->firstOrFail();

    expect($account->name)->toBe('Canteen Income')
        ->and($account->type)->toBe(ChartOfAccount::TYPE_INCOME)
        // Income is credit-normal — derived, never taken from the request.
        ->and($account->normal_balance)->toBe(ChartOfAccount::BALANCE_CREDIT)
        ->and($account->is_locked)->toBeFalse();
});

it('ignores a client-supplied normal_balance that contradicts the type', function () {
    // An asset is debit-normal. A client posting `credit` must not win —
    // this is the value every General Ledger and Balance Sheet figure keys off.
    $this->actingAs(coaAuthAs('accountant'))
        ->post('/admin/chart-of-accounts', validAccountPayload([
            'code' => '1900',
            'type' => ChartOfAccount::TYPE_ASSET,
            'normal_balance' => ChartOfAccount::BALANCE_CREDIT,
        ]))
        ->assertRedirect('/admin/chart-of-accounts');

    expect(ChartOfAccount::query()->where('code', '1900')->value('normal_balance'))
        ->toBe(ChartOfAccount::BALANCE_DEBIT);
});

it('will not let the client mint a second system account', function () {
    // system_code is not in the FormRequest rules, so it is stripped from
    // validated() and never reaches the model. Two AR control accounts would
    // leave posting code with no way to choose between them.
    $this->actingAs(coaAuthAs('accountant'))
        ->post('/admin/chart-of-accounts', validAccountPayload([
            'code' => '1201',
            'system_code' => ChartOfAccount::SYSTEM_AR_CONTROL,
        ]))
        ->assertRedirect('/admin/chart-of-accounts');

    expect(ChartOfAccount::query()->where('code', '1201')->value('system_code'))->toBeNull();
});

it('rejects a duplicate code within the same school', function () {
    ChartOfAccount::factory()->create(['code' => '5100']);

    $this->actingAs(coaAuthAs('accountant'))
        ->post('/admin/chart-of-accounts', validAccountPayload(['code' => '5100']))
        ->assertSessionHasErrors('code');
});

it('allows the same code in two different schools', function () {
    ChartOfAccount::factory()->create(['code' => '5100']);

    $other = School::factory()->create(['slug' => 'coa-other-school']);
    $other->makeCurrent();

    // The other school's cloned chart is wiped first so the code is free —
    // the point under test is the composite (school_id, code) unique, not
    // the clone.
    ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $other->getKey())->delete();

    expect(fn () => ChartOfAccount::factory()->create(['code' => '5100']))
        ->not()->toThrow(Exception::class);
});

it('rejects an unknown account type', function () {
    $this->actingAs(coaAuthAs('accountant'))
        ->post('/admin/chart-of-accounts', validAccountPayload(['type' => 'liabilty']))
        ->assertSessionHasErrors('type');
});

it('rejects a parent account belonging to another school', function () {
    $other = School::factory()->create(['slug' => 'coa-foreign-parent']);
    $foreignParent = ChartOfAccount::query()->withoutGlobalScopes()->create([
        'school_id' => $other->getKey(),
        'code' => '9999',
        'name' => 'Foreign Parent',
        'type' => ChartOfAccount::TYPE_EXPENSE,
        'normal_balance' => ChartOfAccount::BALANCE_DEBIT,
        'cash_flow_category' => ChartOfAccount::CASH_FLOW_OPERATING,
        'is_active' => true,
        'is_locked' => false,
    ]);

    $this->actingAs(coaAuthAs('accountant'))
        ->post('/admin/chart-of-accounts', validAccountPayload([
            'parent_id' => $foreignParent->getKey(),
        ]))
        ->assertSessionHasErrors('parent_id');
});

it('rejects an account that would parent itself', function () {
    $account = ChartOfAccount::factory()->create(['code' => '5200']);

    $this->actingAs(coaAuthAs('accountant'))
        ->patch("/admin/chart-of-accounts/{$account->getKey()}", validAccountPayload([
            'code' => '5200',
            'parent_id' => $account->getKey(),
        ]))
        ->assertSessionHasErrors('parent_id');
});

it('updates an ordinary account', function () {
    $account = ChartOfAccount::factory()->create(['code' => '5300', 'name' => 'Old Name']);

    $this->actingAs(coaAuthAs('accountant'))
        ->patch("/admin/chart-of-accounts/{$account->getKey()}", validAccountPayload([
            'code' => '5300',
            'name' => 'New Name',
        ]))
        ->assertRedirect('/admin/chart-of-accounts');

    expect($account->fresh()->name)->toBe('New Name');
});

it('lets a system account be renamed but not re-coded or retyped', function () {
    $arControl = ChartOfAccount::factory()
        ->asset()
        ->system(ChartOfAccount::SYSTEM_AR_CONTROL)
        ->create(['code' => '1200', 'name' => 'Accounts Receivable']);

    $user = coaAuthAs('accountant');

    // Rename is fine.
    $this->actingAs($user)
        ->patch("/admin/chart-of-accounts/{$arControl->getKey()}", validAccountPayload([
            'code' => '1200',
            'name' => 'Trade Receivables',
            'type' => ChartOfAccount::TYPE_ASSET,
        ]))
        ->assertSessionHasNoErrors();
    expect($arControl->fresh()->name)->toBe('Trade Receivables');

    // Re-coding is refused — posted journal lines refer to this code.
    $this->actingAs($user)
        ->patch("/admin/chart-of-accounts/{$arControl->getKey()}", validAccountPayload([
            'code' => '1250',
            'name' => 'Trade Receivables',
            'type' => ChartOfAccount::TYPE_ASSET,
        ]))
        ->assertSessionHasErrors('code');

    // Retyping is refused — it would invert the normal balance under
    // everything already posted.
    $this->actingAs($user)
        ->patch("/admin/chart-of-accounts/{$arControl->getKey()}", validAccountPayload([
            'code' => '1200',
            'name' => 'Trade Receivables',
            'type' => ChartOfAccount::TYPE_EXPENSE,
        ]))
        ->assertSessionHasErrors('type');

    expect($arControl->fresh()->code)->toBe('1200')
        ->and($arControl->fresh()->type)->toBe(ChartOfAccount::TYPE_ASSET);
});

it('refuses to delete a system account', function () {
    $vatOutput = ChartOfAccount::factory()
        ->liability()
        ->system(ChartOfAccount::SYSTEM_VAT_OUTPUT)
        ->create(['code' => '2200']);

    $this->actingAs(coaAuthAs('super-admin'))
        ->delete("/admin/chart-of-accounts/{$vatOutput->getKey()}")
        ->assertForbidden();

    expect(ChartOfAccount::query()->whereKey($vatOutput->getKey())->exists())->toBeTrue();
});

it('soft-blocks deleting an account that has sub-accounts', function () {
    $parent = ChartOfAccount::factory()->create(['code' => '1100']);
    ChartOfAccount::factory()->create(['code' => '1101', 'parent_id' => $parent->getKey()]);

    $this->actingAs(coaAuthAs('accountant'))
        ->delete("/admin/chart-of-accounts/{$parent->getKey()}")
        ->assertRedirect('/admin/chart-of-accounts')
        ->assertSessionHas('error');

    expect(ChartOfAccount::query()->whereKey($parent->getKey())->exists())->toBeTrue();
});

it('deletes an unreferenced, unlocked account', function () {
    $account = ChartOfAccount::factory()->create(['code' => '5900']);

    $this->actingAs(coaAuthAs('accountant'))
        ->delete("/admin/chart-of-accounts/{$account->getKey()}")
        ->assertRedirect('/admin/chart-of-accounts')
        ->assertSessionHas('success');

    expect(ChartOfAccount::query()->whereKey($account->getKey())->exists())->toBeFalse();
});
