<?php

declare(strict_types=1);

use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeDeduction;
use App\Models\User;

/*
 * /admin/deduction-types resource controller (Week 7, Chunk 5a).
 *
 * Pinned behaviors:
 *  - Auth + role gates: super-admin sees the catalog; payroll-officer, hr,
 *    auditor, and employee get 403.
 *  - Validation: required fields surface as 422 field-level errors.
 *  - Soft-block on destroy: when an EmployeeDeduction subscription points
 *    at the catalog row, destroy() refuses to delete and flashes the
 *    "toggle is_active off instead" guidance. Hard-deleting would also
 *    trip the FK's restrictOnDelete at the DB layer; the controller-level
 *    guard surfaces a friendlier UX.
 *  - Hard-delete only when no subscriptions exist.
 */

function deductionTypeAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/** @return array<string, mixed> */
function validDeductionTypePayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'unit_test_ded',
        'name' => 'Unit-test deduction',
        'is_taxable' => true,
        'calc_method' => DeductionType::CALC_FIXED,
        'source' => DeductionType::SOURCE_EMPLOYEE,
        'is_active' => true,
        'notes' => null,
    ], $overrides);
}

it('allows super-admin to view the index and lists existing rows', function () {
    $user = deductionTypeAuthAs('super-admin');

    DeductionType::factory()->hmo()->create();
    DeductionType::factory()->uniform()->create();

    $this->actingAs($user)
        ->get('/admin/deduction-types')
        ->assertOk()
        ->assertInertia(
            // Chunk 5b ships the TSX files; pass false to skip the file-exists
            // check until then.
            fn ($page) => $page
                ->component('admin/deduction-types/index', false)
                ->has('deductionTypes', 2)
                ->has('calcMethodOptions', 2)
                ->has('sourceOptions', 3),
        );
});

it('allows payroll-officer and hr to view the index (read-only)', function (string $role) {
    $user = deductionTypeAuthAs($role);

    $this->actingAs($user)
        ->get('/admin/deduction-types')
        ->assertOk();
})->with(['payroll-officer', 'hr']);

it('forbids the index for auditor and employee', function (string $role) {
    $user = deductionTypeAuthAs($role);

    $this->actingAs($user)
        ->get('/admin/deduction-types')
        ->assertForbidden();
})->with(['auditor', 'employee']);

it('allows super-admin to render the create form and persist a new row', function () {
    $user = deductionTypeAuthAs('super-admin');

    $this->actingAs($user)
        ->get('/admin/deduction-types/create')
        ->assertOk()
        ->assertInertia(
            // Chunk 5b ships the TSX files; pass false to skip the file-exists
            // check until then.
            fn ($page) => $page
                ->component('admin/deduction-types/create', false)
                ->has('calcMethodOptions', 2)
                ->has('sourceOptions', 3),
        );

    $this->actingAs($user)
        ->from('/admin/deduction-types/create')
        ->post('/admin/deduction-types', validDeductionTypePayload())
        ->assertRedirect('/admin/deduction-types')
        ->assertSessionHas('success');

    expect(DeductionType::query()->where('code', 'unit_test_ded')->exists())->toBeTrue();
});

it('rejects a store request with missing required fields', function () {
    $user = deductionTypeAuthAs('super-admin');

    $this->actingAs($user)
        ->from('/admin/deduction-types/create')
        ->post('/admin/deduction-types', [])
        ->assertSessionHasErrors(['code', 'name', 'is_taxable', 'calc_method', 'source', 'is_active']);

    expect(DeductionType::query()->count())->toBe(0);
});

it('allows payroll-officer and hr to store a new row', function (string $role) {
    $user = deductionTypeAuthAs($role);

    $this->actingAs($user)
        ->post('/admin/deduction-types', validDeductionTypePayload())
        ->assertRedirect('/admin/deduction-types');

    expect(DeductionType::query()->count())->toBe(1);
})->with(['payroll-officer', 'hr']);

it('forbids storing a new row for auditor and employee', function (string $role) {
    $user = deductionTypeAuthAs($role);

    $this->actingAs($user)
        ->post('/admin/deduction-types', validDeductionTypePayload())
        ->assertForbidden();

    expect(DeductionType::query()->count())->toBe(0);
})->with(['auditor', 'employee']);

it('allows super-admin to update an existing row', function () {
    $user = deductionTypeAuthAs('super-admin');

    $row = DeductionType::factory()->hmo()->create();

    $this->actingAs($user)
        ->from("/admin/deduction-types/{$row->id}/edit")
        ->put("/admin/deduction-types/{$row->id}", validDeductionTypePayload([
            'code' => $row->code, // keep the same code — exercises ignore() rule
            'name' => 'HMO Premium (revised)',
            'is_taxable' => false,
            'calc_method' => DeductionType::CALC_FIXED,
            'source' => DeductionType::SOURCE_EMPLOYEE,
            'is_active' => true,
        ]))
        ->assertRedirect('/admin/deduction-types')
        ->assertSessionHas('success');

    expect($row->fresh()->name)->toBe('HMO Premium (revised)');
});

it('soft-blocks destroy when an EmployeeDeduction subscription exists', function () {
    $user = deductionTypeAuthAs('super-admin');

    $row = DeductionType::factory()->hmo()->create();
    EmployeeDeduction::factory()->create(['deduction_type_id' => $row->id]);

    $this->actingAs($user)
        ->delete("/admin/deduction-types/{$row->id}")
        ->assertRedirect('/admin/deduction-types')
        ->assertSessionHas('error');

    expect(DeductionType::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('allows destroy when no subscriptions exist', function () {
    $user = deductionTypeAuthAs('super-admin');

    $row = DeductionType::factory()->hmo()->create();

    $this->actingAs($user)
        ->delete("/admin/deduction-types/{$row->id}")
        ->assertRedirect('/admin/deduction-types')
        ->assertSessionHas('success');

    expect(DeductionType::query()->whereKey($row->id)->exists())->toBeFalse();
});
