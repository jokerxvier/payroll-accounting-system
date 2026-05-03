<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;

/*
 * Per-employee deduction CRUD endpoints (Week 7, Chunk 6).
 *
 * Pinned behaviors:
 *  - Auth + role gates: super-admin / payroll-officer / hr can store/update
 *    AND destroy. Auditor + employee always 403 on writes (including destroy).
 *  - Validation: missing required fields produce 422 field errors.
 *  - Cross-field: amount vs percent flips based on the resolved DeductionType's
 *    calc_method (fixed → amount required + percent forbidden, and vice versa).
 *  - Self-service view: an employee whose profile owns the row can `view()`
 *    via policy (exercised in the per-employee policy tests, not retested here).
 *  - Profile resolution: the URL's {staffId} is the LMS staff id, and the
 *    handler 404s when no payroll profile exists for that staff yet.
 */

function authDeductionAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function deductionStaffAndProfile(): array
{
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();

    $profile = EmployeeProfile::query()->create([
        'lms_staff_id' => (int) $staff->id,
        'employment_classification' => 'regular',
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 5_000_000,
        'is_active' => true,
    ]);

    return [$staff, $profile];
}

it('hr can store a fixed-amount deduction', function () {
    $user = authDeductionAs('hr');
    [$staff, $profile] = deductionStaffAndProfile();

    $type = DeductionType::factory()->create([
        'calc_method' => DeductionType::CALC_FIXED,
    ]);

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/deductions', [
            'deduction_type_id' => $type->id,
            'amount_centavos' => 250_000,
            'percent_basis_points' => null,
            'schedule' => 'every_run',
            'effective_from' => '2026-05-01',
            'effective_to' => null,
            'notes' => 'Unit-test deduction',
        ])
        ->assertRedirect('/employees/'.$staff->id)
        ->assertSessionHas('success');

    expect(EmployeeDeduction::query()->count())->toBe(1);

    $row = EmployeeDeduction::query()->first();
    expect($row->employee_profile_id)->toBe($profile->id)
        ->and($row->deduction_type_id)->toBe($type->id)
        ->and($row->amount_centavos)->toBe(250_000)
        ->and($row->percent_basis_points)->toBeNull()
        ->and($row->schedule)->toBe('every_run');
});

it('hr can store a percent-based deduction', function () {
    $user = authDeductionAs('hr');
    [$staff] = deductionStaffAndProfile();

    $type = DeductionType::factory()->percent()->create();

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/deductions', [
            'deduction_type_id' => $type->id,
            'amount_centavos' => null,
            'percent_basis_points' => 250, // 2.5%
            'schedule' => 'every_run',
            'effective_from' => '2026-05-01',
        ])
        ->assertRedirect('/employees/'.$staff->id)
        ->assertSessionHas('success');

    $row = EmployeeDeduction::query()->first();
    expect($row->amount_centavos)->toBeNull()
        ->and($row->percent_basis_points)->toBe(250);
});

it('rejects a fixed deduction missing the amount', function () {
    $user = authDeductionAs('hr');
    [$staff] = deductionStaffAndProfile();

    $type = DeductionType::factory()->create([
        'calc_method' => DeductionType::CALC_FIXED,
    ]);

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/deductions', [
            'deduction_type_id' => $type->id,
            'amount_centavos' => null,
            'percent_basis_points' => null,
            'schedule' => 'every_run',
            'effective_from' => '2026-05-01',
        ])
        ->assertSessionHasErrors('amount_centavos');

    expect(EmployeeDeduction::query()->count())->toBe(0);
});

it('rejects a percent deduction with an amount also set', function () {
    $user = authDeductionAs('hr');
    [$staff] = deductionStaffAndProfile();

    $type = DeductionType::factory()->percent()->create();

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/deductions', [
            'deduction_type_id' => $type->id,
            'amount_centavos' => 50_000,
            'percent_basis_points' => 100,
            'schedule' => 'every_run',
            'effective_from' => '2026-05-01',
        ])
        ->assertSessionHasErrors('amount_centavos');

    expect(EmployeeDeduction::query()->count())->toBe(0);
});

it('rejects a store with missing required fields', function () {
    $user = authDeductionAs('super-admin');
    [$staff] = deductionStaffAndProfile();

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/deductions', [])
        ->assertSessionHasErrors(['deduction_type_id', 'schedule', 'effective_from']);

    expect(EmployeeDeduction::query()->count())->toBe(0);
});

it('forbids the employee role from storing', function () {
    $user = authDeductionAs('employee');
    [$staff] = deductionStaffAndProfile();

    $type = DeductionType::factory()->create();

    $this->actingAs($user)
        ->post('/employees/'.$staff->id.'/deductions', [
            'deduction_type_id' => $type->id,
            'amount_centavos' => 100_000,
            'schedule' => 'every_run',
            'effective_from' => '2026-05-01',
        ])
        ->assertForbidden();
});

it('forbids the auditor role from storing', function () {
    $user = authDeductionAs('auditor');
    [$staff] = deductionStaffAndProfile();

    $type = DeductionType::factory()->create();

    $this->actingAs($user)
        ->post('/employees/'.$staff->id.'/deductions', [
            'deduction_type_id' => $type->id,
            'amount_centavos' => 100_000,
            'schedule' => 'every_run',
            'effective_from' => '2026-05-01',
        ])
        ->assertForbidden();
});

it('hr can update an existing deduction', function () {
    $user = authDeductionAs('hr');
    [$staff, $profile] = deductionStaffAndProfile();

    $type = DeductionType::factory()->create([
        'calc_method' => DeductionType::CALC_FIXED,
    ]);
    $row = EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile->id,
        'deduction_type_id' => $type->id,
        'amount_centavos' => 100_000,
        'percent_basis_points' => null,
        'schedule' => 'every_run',
        'effective_from' => '2026-01-01',
    ]);

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->patch('/employees/'.$staff->id.'/deductions/'.$row->id, [
            'deduction_type_id' => $type->id,
            'amount_centavos' => 175_000,
            'percent_basis_points' => null,
            'schedule' => 'monthly_first',
            'effective_from' => '2026-01-01',
            'notes' => 'Increased per HR memo',
        ])
        ->assertRedirect('/employees/'.$staff->id)
        ->assertSessionHas('success');

    $row->refresh();
    expect($row->amount_centavos)->toBe(175_000)
        ->and($row->schedule)->toBe('monthly_first')
        ->and($row->notes)->toBe('Increased per HR memo');
});

it('super-admin can destroy a deduction', function () {
    $user = authDeductionAs('super-admin');
    [$staff, $profile] = deductionStaffAndProfile();

    $row = EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/deductions/'.$row->id)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(EmployeeDeduction::query()->whereKey($row->id)->exists())->toBeFalse();
});

it('allows hr to destroy a deduction', function () {
    $user = authDeductionAs('hr');
    [$staff, $profile] = deductionStaffAndProfile();

    $row = EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/deductions/'.$row->id)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(EmployeeDeduction::query()->whereKey($row->id)->exists())->toBeFalse();
});

it('allows payroll-officer to destroy a deduction', function () {
    $user = authDeductionAs('payroll-officer');
    [$staff, $profile] = deductionStaffAndProfile();

    $row = EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/deductions/'.$row->id)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(EmployeeDeduction::query()->whereKey($row->id)->exists())->toBeFalse();
});

it('forbids the auditor role from destroying a deduction', function () {
    $user = authDeductionAs('auditor');
    [$staff, $profile] = deductionStaffAndProfile();

    $row = EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/deductions/'.$row->id)
        ->assertForbidden();

    expect(EmployeeDeduction::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('forbids the employee role from destroying a deduction', function () {
    // The owning-employee carve-out only extends to `view`; an employee may
    // see their own subscription but never delete it. A bare `employee`
    // without a profile is the same shape (the policy short-circuits on
    // role match before the profile check).
    $user = authDeductionAs('employee');
    [$staff, $profile] = deductionStaffAndProfile();

    $row = EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/deductions/'.$row->id)
        ->assertForbidden();

    expect(EmployeeDeduction::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('returns 404 when the bound row belongs to a different employee', function () {
    $user = authDeductionAs('hr');
    [$staff, $profile] = deductionStaffAndProfile();

    // A second profile with its own deduction; we then try to update it via
    // the FIRST staff's URL. The handler must 404 even though the row exists,
    // because the URL/payload pairing is inconsistent.
    $otherStaff = Staff::query()
        ->whereIn('role_id', [1, 4, 5])
        ->where('id', '<>', $staff->id)
        ->first();
    expect($otherStaff)->not->toBeNull();

    $otherProfile = EmployeeProfile::query()->create([
        'lms_staff_id' => (int) $otherStaff->id,
        'employment_classification' => 'regular',
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 4_000_000,
        'is_active' => true,
    ]);

    $type = DeductionType::factory()->create([
        'calc_method' => DeductionType::CALC_FIXED,
    ]);
    $otherRow = EmployeeDeduction::factory()->create([
        'employee_profile_id' => $otherProfile->id,
        'deduction_type_id' => $type->id,
    ]);

    $this->actingAs($user)
        ->patch('/employees/'.$staff->id.'/deductions/'.$otherRow->id, [
            'deduction_type_id' => $type->id,
            'amount_centavos' => 999_999,
            'schedule' => 'every_run',
            'effective_from' => '2026-05-01',
        ])
        ->assertNotFound();

    expect($otherRow->fresh()->amount_centavos)->toBe($otherRow->amount_centavos);
});
