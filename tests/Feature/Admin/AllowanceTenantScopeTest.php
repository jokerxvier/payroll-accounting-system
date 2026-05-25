<?php

declare(strict_types=1);

use App\Http\Requests\Admin\AllowanceStoreRequest;
use App\Models\Pas\Allowance;
use App\Models\Pas\School;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/*
 * Stage F of the multi-tenant catalog conversion (allowances).
 *
 * Pinned behaviours (after Stages A–D):
 *  - Allowance now uses BelongsToTenant. Every read on Allowance::query()
 *    is auto-filtered to Tenant::current()'s school_id; every create
 *    auto-fills school_id from Tenant::current().
 *  - The DB unique on `code` is composite (school_id, code), so two
 *    schools may each hold their own row with the same code.
 *  - The store/update FormRequests scope the unique check to the current
 *    tenant.
 *
 * The global Pest beforeEach binds the default school as the current
 * tenant. Tests that exercise multi-school behaviour switch via
 * makeCurrent().
 */

function allowanceTenantScopeAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

it('does not return another school\'s allowances on the index', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $other = School::factory()->create(['slug' => 'tenant-scope-other']);

    // Seed one row under each school.
    $default->makeCurrent();
    $defaultAllowance = Allowance::factory()->create(['code' => 'default_only_alw']);

    $other->makeCurrent();
    $otherAllowance = Allowance::factory()->create(['code' => 'other_only_alw']);

    // Act as a default-school HR user. NeedsTenant middleware would normally
    // resolve `default` for them; here we re-pin default before the request.
    $default->makeCurrent();
    $hr = allowanceTenantScopeAuthAs('hr');

    $response = $this->actingAs($hr)
        ->get('/admin/allowances')
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('admin/allowances/index', false)
        ->where('allowances', fn ($allowances) => collect($allowances)->pluck('id')->contains($defaultAllowance->id)
            && ! collect($allowances)->pluck('id')->contains($otherAllowance->id),
        ),
    );
});

it('returns 404 when fetching the edit page for another school\'s allowance', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $other = School::factory()->create(['slug' => 'edit-other']);

    // Create the allowance under the other school.
    $other->makeCurrent();
    $otherAllowance = Allowance::factory()->create(['code' => 'cross_tenant_alw']);

    // Switch back to default and act as default's super-admin.
    $default->makeCurrent();
    $admin = allowanceTenantScopeAuthAs('super-admin');

    // Route-model binding runs through the global scope, so the row is
    // invisible from default's tenant context — Laravel responds 404.
    $this->actingAs($admin)
        ->get("/admin/allowances/{$otherAllowance->id}/edit")
        ->assertNotFound();
});

it('returns 404 when updating another school\'s allowance', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $other = School::factory()->create(['slug' => 'update-other']);

    $other->makeCurrent();
    $otherAllowance = Allowance::factory()->create(['code' => 'cross_update_alw']);

    $default->makeCurrent();
    $admin = allowanceTenantScopeAuthAs('super-admin');

    $this->actingAs($admin)
        ->put("/admin/allowances/{$otherAllowance->id}", [
            'code' => 'hacked',
            'name' => 'Hacked',
            'is_taxable' => true,
            'is_de_minimis' => false,
            'de_minimis_cap_centavos' => null,
            'default_amount_centavos' => 100_000,
            'is_active' => true,
            'notes' => null,
        ])
        ->assertNotFound();

    // The other school's row is unchanged.
    $other->makeCurrent();
    expect($otherAllowance->fresh()->code)->toBe('cross_update_alw');
});

it('returns 404 when destroying another school\'s allowance', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $other = School::factory()->create(['slug' => 'destroy-other']);

    $other->makeCurrent();
    $otherAllowance = Allowance::factory()->create(['code' => 'cross_destroy_alw']);

    $default->makeCurrent();
    $admin = allowanceTenantScopeAuthAs('super-admin');

    $this->actingAs($admin)
        ->delete("/admin/allowances/{$otherAllowance->id}")
        ->assertNotFound();

    // The other school's row still exists.
    $other->makeCurrent();
    expect(Allowance::query()->whereKey($otherAllowance->id)->exists())->toBeTrue();
});

it('auto-tags school_id on store from the current tenant', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $admin = allowanceTenantScopeAuthAs('super-admin');

    $this->actingAs($admin)
        ->post('/admin/allowances', [
            'code' => 'auto_tag_alw',
            'name' => 'Auto-tagged',
            'is_taxable' => true,
            'is_de_minimis' => false,
            'de_minimis_cap_centavos' => null,
            'default_amount_centavos' => 150_000,
            'is_active' => true,
            'notes' => null,
        ])
        ->assertRedirect('/admin/allowances')
        ->assertSessionHas('success');

    $row = Allowance::query()->where('code', 'auto_tag_alw')->first();
    expect($row)->not()->toBeNull();
    expect($row->school_id)->toBe($default->getKey());
});

it('allows two schools to hold an allowance with the same code', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $other = School::factory()->create(['slug' => 'dup-code-other']);

    $default->makeCurrent();
    Allowance::factory()->create(['code' => 'dup_code_alw']);

    // The store FormRequest's unique rule is scoped to the current tenant.
    // Switch to the other school, run the validator manually, and assert it
    // passes for the same code.
    $other->makeCurrent();

    $request = AllowanceStoreRequest::create('/admin/allowances', 'POST', [
        'code' => 'dup_code_alw',
        'name' => 'Same code, other school',
        'is_taxable' => true,
        'is_de_minimis' => false,
        'de_minimis_cap_centavos' => null,
        'default_amount_centavos' => 100_000,
        'is_active' => true,
        'notes' => null,
    ]);

    $rules = $request->rules();
    $validator = Validator::make($request->all(), $rules);

    expect($validator->passes())->toBeTrue();

    // And the actual create succeeds: school_id auto-fills from the tenant
    // context, so the composite (school_id, code) unique permits the row.
    $row = Allowance::create([
        'code' => 'dup_code_alw',
        'name' => 'Other school row',
        'is_taxable' => true,
        'is_de_minimis' => false,
        'de_minimis_cap_centavos' => null,
        'default_amount_centavos' => 100_000,
        'is_active' => true,
    ]);

    expect($row->school_id)->toBe($other->getKey());
    expect(Allowance::query()->withoutGlobalScopes()->where('code', 'dup_code_alw')->count())->toBe(2);
});

it('rejects a duplicate code within the same school', function (): void {
    Allowance::factory()->create(['code' => 'within_dup_alw']);

    $request = AllowanceStoreRequest::create('/admin/allowances', 'POST', [
        'code' => 'within_dup_alw',
        'name' => 'Same code, same school',
        'is_taxable' => true,
        'is_de_minimis' => false,
        'de_minimis_cap_centavos' => null,
        'default_amount_centavos' => 100_000,
        'is_active' => true,
        'notes' => null,
    ]);

    $validator = Validator::make($request->all(), $request->rules());

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('code'))->toBeTrue();
});
