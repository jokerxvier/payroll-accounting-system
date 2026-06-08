<?php

declare(strict_types=1);

use App\Http\Requests\Admin\DeductionTypeStoreRequest;
use App\Models\Pas\DeductionType;
use App\Models\Pas\School;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/*
 * Stage F of the multi-tenant catalog conversion (deduction types).
 *
 * Same shape as AllowanceTenantScopeTest — the BelongsToTenant trait + the
 * composite (school_id, code) unique are mirrored on this catalog.
 */

function deductionTypeTenantScopeAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

it('does not return another school\'s deduction types on the index', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $other = School::factory()->create(['slug' => 'ded-tenant-other']);

    $default->makeCurrent();
    $defaultRow = DeductionType::factory()->create(['code' => 'default_only_ded']);

    $other->makeCurrent();
    $otherRow = DeductionType::factory()->create(['code' => 'other_only_ded']);

    $default->makeCurrent();
    $hr = deductionTypeTenantScopeAuthAs('hr');

    $response = $this->actingAs($hr)
        ->get('/admin/deduction-types')
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('admin/deduction-types/index', false)
        ->where('deductionTypes', fn ($rows) => collect($rows)->pluck('id')->contains($defaultRow->id)
            && ! collect($rows)->pluck('id')->contains($otherRow->id),
        ),
    );
});

it('returns 404 when fetching the edit page for another school\'s deduction type', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $other = School::factory()->create(['slug' => 'ded-edit-other']);

    $other->makeCurrent();
    $otherRow = DeductionType::factory()->create(['code' => 'cross_tenant_ded']);

    $default->makeCurrent();
    $admin = deductionTypeTenantScopeAuthAs('super-admin');

    $this->actingAs($admin)
        ->get("/admin/deduction-types/{$otherRow->id}/edit")
        ->assertNotFound();
});

it('returns 404 when updating another school\'s deduction type', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $other = School::factory()->create(['slug' => 'ded-update-other']);

    $other->makeCurrent();
    $otherRow = DeductionType::factory()->create(['code' => 'cross_update_ded']);

    $default->makeCurrent();
    $admin = deductionTypeTenantScopeAuthAs('super-admin');

    $this->actingAs($admin)
        ->put("/admin/deduction-types/{$otherRow->id}", [
            'code' => 'hacked',
            'name' => 'Hacked',
            'is_taxable' => true,
            'calc_method' => DeductionType::CALC_FIXED,
            'source' => DeductionType::SOURCE_EMPLOYEE,
            'is_active' => true,
            'notes' => null,
        ])
        ->assertNotFound();

    $other->makeCurrent();
    expect($otherRow->fresh()->code)->toBe('cross_update_ded');
});

it('returns 404 when destroying another school\'s deduction type', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $other = School::factory()->create(['slug' => 'ded-destroy-other']);

    $other->makeCurrent();
    $otherRow = DeductionType::factory()->create(['code' => 'cross_destroy_ded']);

    $default->makeCurrent();
    $admin = deductionTypeTenantScopeAuthAs('super-admin');

    $this->actingAs($admin)
        ->delete("/admin/deduction-types/{$otherRow->id}")
        ->assertNotFound();

    $other->makeCurrent();
    expect(DeductionType::query()->whereKey($otherRow->id)->exists())->toBeTrue();
});

it('auto-tags school_id on store from the current tenant', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $admin = deductionTypeTenantScopeAuthAs('super-admin');

    $this->actingAs($admin)
        ->post('/admin/deduction-types', [
            'code' => 'auto_tag_ded',
            'name' => 'Auto-tagged',
            'is_taxable' => true,
            'calc_method' => DeductionType::CALC_FIXED,
            'source' => DeductionType::SOURCE_EMPLOYEE,
            'is_active' => true,
            'notes' => null,
        ])
        ->assertRedirect('/admin/deduction-types')
        ->assertSessionHas('success');

    $row = DeductionType::query()->where('code', 'auto_tag_ded')->first();
    expect($row)->not()->toBeNull();
    expect($row->school_id)->toBe($default->getKey());
});

it('allows two schools to hold a deduction type with the same code', function (): void {
    $default = School::query()->where('slug', 'default')->first();
    $other = School::factory()->create(['slug' => 'ded-dup-code-other']);

    $default->makeCurrent();
    DeductionType::factory()->create(['code' => 'dup_code_ded']);

    $other->makeCurrent();

    $request = DeductionTypeStoreRequest::create('/admin/deduction-types', 'POST', [
        'code' => 'dup_code_ded',
        'name' => 'Same code, other school',
        'is_taxable' => true,
        'calc_method' => DeductionType::CALC_FIXED,
        'source' => DeductionType::SOURCE_EMPLOYEE,
        'is_active' => true,
        'notes' => null,
    ]);

    $validator = Validator::make($request->all(), $request->rules());

    expect($validator->passes())->toBeTrue();

    $row = DeductionType::create([
        'code' => 'dup_code_ded',
        'name' => 'Other school row',
        'is_taxable' => true,
        'calc_method' => DeductionType::CALC_FIXED,
        'source' => DeductionType::SOURCE_EMPLOYEE,
        'is_active' => true,
    ]);

    expect($row->school_id)->toBe($other->getKey());
    expect(DeductionType::query()->withoutGlobalScopes()->where('code', 'dup_code_ded')->count())->toBe(2);
});

it('rejects a duplicate code within the same school', function (): void {
    DeductionType::factory()->create(['code' => 'within_dup_ded']);

    $request = DeductionTypeStoreRequest::create('/admin/deduction-types', 'POST', [
        'code' => 'within_dup_ded',
        'name' => 'Same code, same school',
        'is_taxable' => true,
        'calc_method' => DeductionType::CALC_FIXED,
        'source' => DeductionType::SOURCE_EMPLOYEE,
        'is_active' => true,
        'notes' => null,
    ]);

    $validator = Validator::make($request->all(), $request->rules());

    expect($validator->passes())->toBeFalse();
    expect($validator->errors()->has('code'))->toBeTrue();
});
