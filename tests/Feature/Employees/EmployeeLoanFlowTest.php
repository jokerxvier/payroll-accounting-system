<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;

/*
 * Per-employee loan CRUD endpoints (Week 7, Chunk 6).
 *
 * Pinned behaviors:
 *  - Auth + role gates: super-admin / payroll-officer / hr can store/update
 *    AND destroy. Auditor + employee always 403 on writes (including destroy).
 *  - Server-controlled fields: outstanding_balance_centavos initialises from
 *    principal at create time and is NOT editable via update; lock_version is
 *    initialised to 0 and is NOT editable via update. The update FormRequest
 *    drops these keys entirely so even if a client sends them they never reach
 *    the model.
 *  - Validation: required fields surface as 422 errors.
 */

function authLoanAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function loanStaffAndProfile(): array
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

it('hr can store a loan and outstanding initialises from principal', function () {
    $user = authLoanAs('hr');
    [$staff, $profile] = loanStaffAndProfile();

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/loans', [
            'code' => 'LN-2026-001',
            'principal_centavos' => 6_000_000,
            'monthly_amortization_centavos' => 500_000,
            'schedule' => 'every_run',
            'started_on' => '2026-05-01',
            'notes' => 'SSS salary loan',
        ])
        ->assertRedirect('/employees/'.$staff->id)
        ->assertSessionHas('success');

    $row = EmployeeLoan::query()->first();
    expect($row->employee_profile_id)->toBe($profile->id)
        ->and($row->code)->toBe('LN-2026-001')
        ->and($row->principal_centavos)->toBe(6_000_000)
        ->and($row->outstanding_balance_centavos)->toBe(6_000_000)
        ->and($row->monthly_amortization_centavos)->toBe(500_000)
        ->and($row->lock_version)->toBe(0)
        ->and($row->closed_on)->toBeNull();
});

it('rejects a store with missing required fields', function () {
    $user = authLoanAs('super-admin');
    [$staff] = loanStaffAndProfile();

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/loans', [])
        ->assertSessionHasErrors([
            'code',
            'principal_centavos',
            'monthly_amortization_centavos',
            'schedule',
            'started_on',
        ]);

    expect(EmployeeLoan::query()->count())->toBe(0);
});

it('forbids the auditor role from storing', function () {
    $user = authLoanAs('auditor');
    [$staff] = loanStaffAndProfile();

    $this->actingAs($user)
        ->post('/employees/'.$staff->id.'/loans', [
            'code' => 'LN-X',
            'principal_centavos' => 1_000_000,
            'monthly_amortization_centavos' => 100_000,
            'schedule' => 'every_run',
            'started_on' => '2026-05-01',
        ])
        ->assertForbidden();
});

it('hr can update editable fields without disturbing outstanding or lock_version', function () {
    $user = authLoanAs('hr');
    [$staff, $profile] = loanStaffAndProfile();

    $row = EmployeeLoan::factory()->create([
        'employee_profile_id' => $profile->id,
        'code' => 'LN-OLD',
        'principal_centavos' => 6_000_000,
        'outstanding_balance_centavos' => 4_500_000,
        'monthly_amortization_centavos' => 500_000,
        'lock_version' => 3,
        'started_on' => '2025-01-01',
    ]);

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->patch('/employees/'.$staff->id.'/loans/'.$row->id, [
            'code' => 'LN-NEW',
            'monthly_amortization_centavos' => 750_000,
            'schedule' => 'monthly_last',
            'notes' => 'Bumped amortization at employee request',
            // Attempt to also slip in protected fields — they must be ignored.
            'outstanding_balance_centavos' => 100,
            'lock_version' => 999,
            'principal_centavos' => 1,
            'started_on' => '2030-01-01',
            'closed_on' => '2030-12-31',
        ])
        ->assertRedirect('/employees/'.$staff->id)
        ->assertSessionHas('success');

    $row->refresh();
    expect($row->code)->toBe('LN-NEW')
        ->and($row->monthly_amortization_centavos)->toBe(750_000)
        ->and($row->schedule)->toBe('monthly_last')
        ->and($row->notes)->toBe('Bumped amortization at employee request')
        // Protected fields unchanged.
        ->and($row->outstanding_balance_centavos)->toBe(4_500_000)
        ->and($row->lock_version)->toBe(3)
        ->and($row->principal_centavos)->toBe(6_000_000)
        ->and($row->started_on->toDateString())->toBe('2025-01-01')
        ->and($row->closed_on)->toBeNull();
});

it('super-admin can destroy a loan', function () {
    $user = authLoanAs('super-admin');
    [$staff, $profile] = loanStaffAndProfile();

    $row = EmployeeLoan::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/loans/'.$row->id)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(EmployeeLoan::query()->whereKey($row->id)->exists())->toBeFalse();
});

it('allows payroll-officer to destroy a loan', function () {
    $user = authLoanAs('payroll-officer');
    [$staff, $profile] = loanStaffAndProfile();

    $row = EmployeeLoan::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/loans/'.$row->id)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(EmployeeLoan::query()->whereKey($row->id)->exists())->toBeFalse();
});

it('allows hr to destroy a loan', function () {
    $user = authLoanAs('hr');
    [$staff, $profile] = loanStaffAndProfile();

    $row = EmployeeLoan::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/loans/'.$row->id)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(EmployeeLoan::query()->whereKey($row->id)->exists())->toBeFalse();
});

it('forbids the auditor role from destroying a loan', function () {
    $user = authLoanAs('auditor');
    [$staff, $profile] = loanStaffAndProfile();

    $row = EmployeeLoan::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/loans/'.$row->id)
        ->assertForbidden();

    expect(EmployeeLoan::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('forbids the employee role from destroying a loan', function () {
    // The owning-employee carve-out only extends to `view`; an employee may
    // see their own loan but never delete it.
    $user = authLoanAs('employee');
    [$staff, $profile] = loanStaffAndProfile();

    $row = EmployeeLoan::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/loans/'.$row->id)
        ->assertForbidden();

    expect(EmployeeLoan::query()->whereKey($row->id)->exists())->toBeTrue();
});

it('returns 404 when the staff has no payroll profile yet', function () {
    $user = authLoanAs('hr');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect(EmployeeProfile::query()->where('lms_staff_id', $staff->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->post('/employees/'.$staff->id.'/loans', [
            'code' => 'LN-X',
            'principal_centavos' => 1_000_000,
            'monthly_amortization_centavos' => 100_000,
            'schedule' => 'every_run',
            'started_on' => '2026-05-01',
        ])
        ->assertNotFound();

    expect(EmployeeLoan::query()->count())->toBe(0);
});
