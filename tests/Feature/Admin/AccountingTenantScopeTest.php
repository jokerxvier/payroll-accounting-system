<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use App\Models\User;

/*
 * Tenant scoping for the three Phase 5 Slice 1 tables.
 *
 * All three use BelongsToTenant, so every read is auto-filtered to
 * Tenant::current()'s school_id and every create auto-fills it. The DB
 * uniques are composite (school_id, code), so two schools may each hold
 * their own "1100" or "VAT_12_SALES" or "2026-09".
 *
 * The global Pest beforeEach binds the default school as current tenant;
 * tests exercising multi-school behaviour switch with makeCurrent().
 *
 * Cross-tenant FK integrity on the cloned catalogs is covered separately by
 * AccountingCatalogAutoCloneTest.
 */

beforeEach(function (): void {
    Contact::query()->withoutGlobalScopes()->delete();
    TaxRate::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
});

function accountingTenantAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

it('hides another school accounts from the chart index', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $other = School::factory()->create(['slug' => 'coa-scope-other']);

    $default->makeCurrent();
    $mine = ChartOfAccount::factory()->create(['code' => '1100']);

    $other->makeCurrent();
    ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $other->getKey())->delete();
    $theirs = ChartOfAccount::factory()->create(['code' => '1100']);

    $default->makeCurrent();

    $this->actingAs(accountingTenantAuthAs('accountant'))
        ->get('/admin/chart-of-accounts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/chart-of-accounts/index', false)
            ->where('accounts', fn ($accounts) => collect($accounts)->pluck('id')->contains($mine->id)
                && ! collect($accounts)->pluck('id')->contains($theirs->id),
            ),
        );
});

it('404s when updating another school account', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $other = School::factory()->create(['slug' => 'coa-scope-edit']);

    $other->makeCurrent();
    ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $other->getKey())->delete();
    $theirs = ChartOfAccount::factory()->create(['code' => '9100']);

    $default->makeCurrent();

    // Route-model binding runs through the global scope, so the row is
    // invisible and binding fails rather than leaking another tenant's data.
    // Asserted on PATCH because editing now happens in a sheet — there is no
    // /edit page route to probe.
    $this->actingAs(accountingTenantAuthAs('accountant'))
        ->patch("/admin/chart-of-accounts/{$theirs->getKey()}", [
            'code' => '9100',
            'name' => 'Hijacked',
            'type' => ChartOfAccount::TYPE_EXPENSE,
            'cash_flow_category' => ChartOfAccount::CASH_FLOW_OPERATING,
            'is_active' => true,
        ])
        ->assertNotFound();

    expect($theirs->fresh()->name)->not()->toBe('Hijacked');
});

it('404s when editing another school tax rate', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $other = School::factory()->create(['slug' => 'tax-scope-edit']);

    $other->makeCurrent();
    TaxRate::query()->withoutGlobalScopes()
        ->where('school_id', $other->getKey())->delete();
    $theirs = TaxRate::factory()->vatSales()->create(['account_id' => null]);

    $default->makeCurrent();

    $this->actingAs(accountingTenantAuthAs('accountant'))
        ->get("/admin/tax-rates/{$theirs->getKey()}/edit")
        ->assertNotFound();
});

it('404s when closing another school accounting period', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $other = School::factory()->create(['slug' => 'period-scope-close']);

    $other->makeCurrent();
    $theirs = AccountingPeriod::factory()->create(['code' => '2026-12']);

    $default->makeCurrent();

    $this->actingAs(accountingTenantAuthAs('accountant'))
        ->post("/admin/accounting-periods/{$theirs->getKey()}/close")
        ->assertNotFound();

    expect($theirs->fresh()->status)->toBe(AccountingPeriod::STATUS_OPEN);
});

it('auto-fills school_id from the current tenant on create', function (): void {
    $other = School::factory()->create(['slug' => 'autofill-target']);
    $other->makeCurrent();

    $account = ChartOfAccount::factory()->create(['code' => '7777']);
    $period = AccountingPeriod::factory()->create(['code' => '2027-01']);
    $rate = TaxRate::factory()->exempt()->create();

    expect($account->school_id)->toBe($other->getKey())
        ->and($period->school_id)->toBe($other->getKey())
        ->and($rate->school_id)->toBe($other->getKey());
});

it('lets two schools each hold the same codes', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $other = School::factory()->create(['slug' => 'dup-codes']);

    $default->makeCurrent();
    ChartOfAccount::factory()->create(['code' => '1100']);
    TaxRate::factory()->exempt()->create();
    AccountingPeriod::factory()->create(['code' => '2026-09']);

    $other->makeCurrent();
    ChartOfAccount::query()->withoutGlobalScopes()
        ->where('school_id', $other->getKey())->delete();
    TaxRate::query()->withoutGlobalScopes()
        ->where('school_id', $other->getKey())->delete();

    // The composite (school_id, code) uniques make all three legal.
    ChartOfAccount::factory()->create(['code' => '1100']);
    TaxRate::factory()->exempt()->create();
    AccountingPeriod::factory()->create(['code' => '2026-09']);

    expect(ChartOfAccount::query()->withoutGlobalScopes()->where('code', '1100')->count())->toBe(2)
        ->and(TaxRate::query()->withoutGlobalScopes()->where('code', 'VAT_EXEMPT')->count())->toBe(2)
        ->and(AccountingPeriod::query()->withoutGlobalScopes()->where('code', '2026-09')->count())->toBe(2);
});

it('scopes the period overlap check to the current school', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $other = School::factory()->create(['slug' => 'overlap-scope']);

    // Another school already owns September 2026...
    $other->makeCurrent();
    AccountingPeriod::factory()->create([
        'code' => '2026-09',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
    ]);

    // ...which must not block this school from creating its own.
    $default->makeCurrent();

    $this->actingAs(accountingTenantAuthAs('accountant'))
        ->post('/admin/accounting-periods', [
            'code' => '2026-09',
            'name' => 'September 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'fiscal_year' => 2026,
        ])
        ->assertSessionHasNoErrors();

    expect(AccountingPeriod::query()->where('code', '2026-09')->count())->toBe(1);
});

it('hides another school contacts from the register', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $other = School::factory()->create(['slug' => 'contact-scope-other']);

    $default->makeCurrent();
    $mine = Contact::factory()->create(['code' => 'MINE']);

    $other->makeCurrent();
    $theirs = Contact::factory()->create(['code' => 'THEIRS']);

    $default->makeCurrent();

    $this->actingAs(accountingTenantAuthAs('accountant'))
        ->get('/admin/contacts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/contacts/index', false)
            ->where('contacts.data', fn ($rows) => collect($rows)->pluck('id')->contains($mine->id)
                && ! collect($rows)->pluck('id')->contains($theirs->id),
            ),
        );
});

it('404s when updating another school contact', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $other = School::factory()->create(['slug' => 'contact-scope-edit']);

    $other->makeCurrent();
    $theirs = Contact::factory()->create(['code' => 'THEIRS', 'name' => 'Theirs Ltd']);

    $default->makeCurrent();

    // Route-model binding runs through the global scope, so the row is
    // invisible and binding fails rather than leaking another tenant's data.
    $this->actingAs(accountingTenantAuthAs('accountant'))
        ->patch("/admin/contacts/{$theirs->getKey()}", [
            'code' => 'THEIRS',
            'name' => 'Hijacked',
            'is_customer' => true,
            'is_supplier' => false,
            'is_active' => true,
        ])
        ->assertNotFound();

    expect($theirs->fresh()->name)->toBe('Theirs Ltd');
});

it('lets two schools each hold the same contact code and TIN', function (): void {
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $other = School::factory()->create(['slug' => 'contact-dup-codes']);

    $default->makeCurrent();
    Contact::factory()->withTin('123456789')->create(['code' => 'ACME']);

    $other->makeCurrent();

    // Contacts are NOT cloned to a new school — a customer list is business
    // data, not a catalog template — so the register starts empty and the
    // composite uniques make the same code and TIN legal here.
    expect(Contact::query()->count())->toBe(0);

    Contact::factory()->withTin('123456789')->create(['code' => 'ACME']);

    expect(Contact::query()->withoutGlobalScopes()->where('code', 'ACME')->count())->toBe(2);
});
