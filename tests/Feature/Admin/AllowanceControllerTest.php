<?php

declare(strict_types=1);

use App\Models\Pas\Allowance;
use App\Models\Pas\EmployeeAllowance;
use App\Models\User;

/*
 * /admin/allowances resource controller (Week 7, Chunk 5a).
 *
 * Pinned behaviors:
 *  - Auth + role gates: super-admin sees the catalog; payroll-officer, hr,
 *    auditor, and employee get 403.
 *  - Validation: required fields surface as 422 field-level errors.
 *  - Conditional invariant on de_minimis_cap_centavos (Decision 5):
 *      * REQUIRED when is_de_minimis = true
 *      * NULL allowed when is_de_minimis = false
 *  - Soft-block on destroy: when an EmployeeAllowance subscription points
 *    at the catalog row, destroy() refuses to delete and flashes the
 *    "toggle is_active off instead" guidance.
 *  - Hard-delete only when no subscriptions exist.
 */

function allowanceAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/** @return array<string, mixed> */
function validAllowancePayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'unit_test_alw',
        'name' => 'Unit-test allowance',
        'is_taxable' => true,
        'is_de_minimis' => false,
        'de_minimis_cap_centavos' => null,
        'default_amount_centavos' => 100_000,
        'is_active' => true,
        'notes' => null,
    ], $overrides);
}

it('allows super-admin to view the index and lists existing rows', function () {
    $user = allowanceAuthAs('super-admin');

    Allowance::factory()->riceSubsidy()->create();
    Allowance::factory()->transportation()->create();

    $this->actingAs($user)
        ->get('/admin/allowances')
        ->assertOk()
        ->assertInertia(
            // Chunk 5b ships the TSX files; pass false to skip the file-exists
            // check until then.
            fn ($page) => $page
                ->component('admin/allowances/index', false)
                ->has('allowances', 2),
        );
});

it('forbids non-super-admin roles from the index', function (string $role) {
    $user = allowanceAuthAs($role);

    $this->actingAs($user)
        ->get('/admin/allowances')
        ->assertForbidden();
})->with(['payroll-officer', 'hr', 'auditor', 'employee']);

it('allows super-admin to render the create form and persist a new row', function () {
    $user = allowanceAuthAs('super-admin');

    $this->actingAs($user)
        ->get('/admin/allowances/create')
        ->assertOk()
        // Chunk 5b ships the TSX files; pass false to skip the file-exists
        // check until then.
        ->assertInertia(fn ($page) => $page->component('admin/allowances/create', false));

    $this->actingAs($user)
        ->from('/admin/allowances/create')
        ->post('/admin/allowances', validAllowancePayload())
        ->assertRedirect('/admin/allowances')
        ->assertSessionHas('success');

    expect(Allowance::query()->where('code', 'unit_test_alw')->exists())->toBeTrue();
});

it('rejects a store request with missing required fields', function () {
    $user = allowanceAuthAs('super-admin');

    $this->actingAs($user)
        ->from('/admin/allowances/create')
        ->post('/admin/allowances', [])
        ->assertSessionHasErrors(['code', 'name', 'is_taxable', 'is_de_minimis', 'is_active']);

    expect(Allowance::query()->count())->toBe(0);
});

it('forbids non-super-admin roles from storing a new row', function (string $role) {
    $user = allowanceAuthAs($role);

    $this->actingAs($user)
        ->post('/admin/allowances', validAllowancePayload())
        ->assertForbidden();

    expect(Allowance::query()->count())->toBe(0);
})->with(['payroll-officer', 'hr', 'auditor', 'employee']);

it('allows super-admin to update an existing row', function () {
    $user = allowanceAuthAs('super-admin');

    $row = Allowance::factory()->transportation()->create();

    $this->actingAs($user)
        ->from("/admin/allowances/{$row->id}/edit")
        ->put("/admin/allowances/{$row->id}", validAllowancePayload([
            'code' => $row->code,
            'name' => 'Transportation (revised)',
            'is_taxable' => true,
            'is_de_minimis' => false,
            'de_minimis_cap_centavos' => null,
            'default_amount_centavos' => 350_000,
            'is_active' => true,
        ]))
        ->assertRedirect('/admin/allowances')
        ->assertSessionHas('success');

    expect($row->fresh()->name)->toBe('Transportation (revised)')
        ->and($row->fresh()->default_amount_centavos)->toBe(350_000);
});

it('soft-blocks destroy when an EmployeeAllowance subscription exists', function () {
    $user = allowanceAuthAs('super-admin');

    $row = Allowance::factory()->transportation()->create();
    EmployeeAllowance::factory()->create(['allowance_id' => $row->id]);

    $this->actingAs($user)
        ->delete("/admin/allowances/{$row->id}")
        ->assertRedirect('/admin/allowances')
        ->assertSessionHas('error');

    expect(Allowance::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('allows destroy when no subscriptions exist', function () {
    $user = allowanceAuthAs('super-admin');

    $row = Allowance::factory()->transportation()->create();

    $this->actingAs($user)
        ->delete("/admin/allowances/{$row->id}")
        ->assertRedirect('/admin/allowances')
        ->assertSessionHas('success');

    expect(Allowance::query()->whereKey($row->id)->exists())->toBeFalse();
});

it('requires de_minimis_cap_centavos when is_de_minimis is true', function () {
    $user = allowanceAuthAs('super-admin');

    $this->actingAs($user)
        ->from('/admin/allowances/create')
        ->post('/admin/allowances', validAllowancePayload([
            'code' => 'rice_test',
            'is_de_minimis' => true,
            'de_minimis_cap_centavos' => null,
        ]))
        ->assertSessionHasErrors('de_minimis_cap_centavos');

    expect(Allowance::query()->where('code', 'rice_test')->exists())->toBeFalse();
});

it('allows null de_minimis_cap_centavos when is_de_minimis is false', function () {
    $user = allowanceAuthAs('super-admin');

    $this->actingAs($user)
        ->from('/admin/allowances/create')
        ->post('/admin/allowances', validAllowancePayload([
            'code' => 'taxable_alw_test',
            'is_de_minimis' => false,
            'de_minimis_cap_centavos' => null,
        ]))
        ->assertRedirect('/admin/allowances')
        ->assertSessionHas('success');

    expect(Allowance::query()->where('code', 'taxable_alw_test')->exists())->toBeTrue();
});
