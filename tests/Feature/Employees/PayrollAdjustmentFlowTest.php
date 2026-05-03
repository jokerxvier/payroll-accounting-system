<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
use App\Models\User;

/*
 * Per-employee payroll adjustment CRUD endpoints (Week 7, Chunk 6).
 *
 * One-off adjustments (bonus, ad-hoc deduction, back-pay correction) — flat
 * shape with no catalog FK. Authorization mirrors the other Employees/*
 * controllers: super-admin / payroll-officer / hr write, super-admin only
 * destroys, auditor + employee always 403 on writes.
 */

function authAdjustmentAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function adjustmentStaffAndProfile(): array
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

it('hr can store a taxable addition', function () {
    $user = authAdjustmentAs('hr');
    [$staff, $profile] = adjustmentStaffAndProfile();

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/adjustments', [
            'kind' => PayrollAdjustment::KIND_ADDITION,
            'is_taxable' => true,
            'amount_centavos' => 1_000_000,
            'applies_on' => '2026-05-15',
            'label' => 'Mid-year bonus',
            'reason' => 'Q1 performance',
        ])
        ->assertRedirect('/employees/'.$staff->id)
        ->assertSessionHas('success');

    $row = PayrollAdjustment::query()->first();
    expect($row->employee_profile_id)->toBe($profile->id)
        ->and($row->kind)->toBe(PayrollAdjustment::KIND_ADDITION)
        ->and($row->is_taxable)->toBeTrue()
        ->and($row->amount_centavos)->toBe(1_000_000)
        ->and($row->label)->toBe('Mid-year bonus')
        ->and($row->reason)->toBe('Q1 performance')
        ->and($row->applied_pay_period_id)->toBeNull();
});

it('rejects a store with missing required fields', function () {
    $user = authAdjustmentAs('super-admin');
    [$staff] = adjustmentStaffAndProfile();

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/adjustments', [])
        ->assertSessionHasErrors([
            'kind',
            'is_taxable',
            'amount_centavos',
            'applies_on',
            'label',
        ]);

    expect(PayrollAdjustment::query()->count())->toBe(0);
});

it('rejects an invalid kind value', function () {
    $user = authAdjustmentAs('super-admin');
    [$staff] = adjustmentStaffAndProfile();

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/adjustments', [
            'kind' => 'commission',
            'is_taxable' => true,
            'amount_centavos' => 100_000,
            'applies_on' => '2026-05-15',
            'label' => 'Bad kind',
        ])
        ->assertSessionHasErrors('kind');
});

it('forbids the auditor role from storing', function () {
    $user = authAdjustmentAs('auditor');
    [$staff] = adjustmentStaffAndProfile();

    $this->actingAs($user)
        ->post('/employees/'.$staff->id.'/adjustments', [
            'kind' => PayrollAdjustment::KIND_ADDITION,
            'is_taxable' => true,
            'amount_centavos' => 100_000,
            'applies_on' => '2026-05-15',
            'label' => 'Test',
        ])
        ->assertForbidden();
});

it('payroll-officer can update an adjustment', function () {
    $user = authAdjustmentAs('payroll-officer');
    [$staff, $profile] = adjustmentStaffAndProfile();

    $row = PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
        'kind' => PayrollAdjustment::KIND_ADDITION,
        'amount_centavos' => 500_000,
        'label' => 'Original',
    ]);

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->patch('/employees/'.$staff->id.'/adjustments/'.$row->id, [
            'kind' => PayrollAdjustment::KIND_DEDUCTION,
            'is_taxable' => false,
            'amount_centavos' => 250_000,
            'applies_on' => '2026-05-20',
            'label' => 'Cash advance recovery',
            'reason' => 'Repayment per HR memo',
        ])
        ->assertRedirect('/employees/'.$staff->id)
        ->assertSessionHas('success');

    $row->refresh();
    expect($row->kind)->toBe(PayrollAdjustment::KIND_DEDUCTION)
        ->and($row->is_taxable)->toBeFalse()
        ->and($row->amount_centavos)->toBe(250_000)
        ->and($row->label)->toBe('Cash advance recovery');
});

it('super-admin can destroy an adjustment', function () {
    $user = authAdjustmentAs('super-admin');
    [$staff, $profile] = adjustmentStaffAndProfile();

    $row = PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/adjustments/'.$row->id)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(PayrollAdjustment::query()->whereKey($row->id)->exists())->toBeFalse();
});

it('forbids hr from destroying an adjustment', function () {
    $user = authAdjustmentAs('hr');
    [$staff, $profile] = adjustmentStaffAndProfile();

    $row = PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/adjustments/'.$row->id)
        ->assertForbidden();

    expect(PayrollAdjustment::query()->whereKey($row->id)->exists())->toBeTrue();
});
