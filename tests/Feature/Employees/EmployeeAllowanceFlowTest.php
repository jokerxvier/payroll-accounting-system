<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\Allowance;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;

/*
 * Per-employee allowance CRUD endpoints (Week 7, Chunk 6).
 *
 * Same auth + validation shape as the deduction tests; allowance is simpler
 * because there is no fixed/percent split — `amount_centavos` is plainly
 * required and positive.
 */

function authAllowanceAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function allowanceStaffAndProfile(): array
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

it('hr can store an allowance subscription', function () {
    $user = authAllowanceAs('hr');
    [$staff, $profile] = allowanceStaffAndProfile();

    $allowance = Allowance::factory()->riceSubsidy()->create();

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/allowances', [
            'allowance_id' => $allowance->id,
            'amount_centavos' => 200_000,
            'schedule' => 'every_run',
            'effective_from' => '2026-05-01',
            'effective_to' => null,
        ])
        ->assertRedirect('/employees/'.$staff->id)
        ->assertSessionHas('success');

    $row = EmployeeAllowance::query()->first();
    expect($row->employee_profile_id)->toBe($profile->id)
        ->and($row->allowance_id)->toBe($allowance->id)
        ->and($row->amount_centavos)->toBe(200_000)
        ->and($row->schedule)->toBe('every_run');
});

it('rejects a store with missing required fields', function () {
    $user = authAllowanceAs('super-admin');
    [$staff] = allowanceStaffAndProfile();

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/allowances', [])
        ->assertSessionHasErrors(['allowance_id', 'amount_centavos', 'schedule', 'effective_from']);

    expect(EmployeeAllowance::query()->count())->toBe(0);
});

it('rejects an effective_to before effective_from', function () {
    $user = authAllowanceAs('super-admin');
    [$staff] = allowanceStaffAndProfile();

    $allowance = Allowance::factory()->create();

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->post('/employees/'.$staff->id.'/allowances', [
            'allowance_id' => $allowance->id,
            'amount_centavos' => 100_000,
            'schedule' => 'every_run',
            'effective_from' => '2026-05-15',
            'effective_to' => '2026-05-01',
        ])
        ->assertSessionHasErrors('effective_to');
});

it('forbids the auditor role from storing', function () {
    $user = authAllowanceAs('auditor');
    [$staff] = allowanceStaffAndProfile();

    $allowance = Allowance::factory()->create();

    $this->actingAs($user)
        ->post('/employees/'.$staff->id.'/allowances', [
            'allowance_id' => $allowance->id,
            'amount_centavos' => 100_000,
            'schedule' => 'every_run',
            'effective_from' => '2026-05-01',
        ])
        ->assertForbidden();
});

it('payroll-officer can update an allowance', function () {
    $user = authAllowanceAs('payroll-officer');
    [$staff, $profile] = allowanceStaffAndProfile();

    $allowance = Allowance::factory()->create();
    $row = EmployeeAllowance::factory()->create([
        'employee_profile_id' => $profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 100_000,
        'schedule' => 'every_run',
    ]);

    $this->actingAs($user)
        ->from('/employees/'.$staff->id)
        ->patch('/employees/'.$staff->id.'/allowances/'.$row->id, [
            'allowance_id' => $allowance->id,
            'amount_centavos' => 350_000,
            'schedule' => 'monthly_first',
            'effective_from' => '2026-01-01',
        ])
        ->assertRedirect('/employees/'.$staff->id)
        ->assertSessionHas('success');

    $row->refresh();
    expect($row->amount_centavos)->toBe(350_000)
        ->and($row->schedule)->toBe('monthly_first');
});

it('super-admin can destroy an allowance', function () {
    $user = authAllowanceAs('super-admin');
    [$staff, $profile] = allowanceStaffAndProfile();

    $row = EmployeeAllowance::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/allowances/'.$row->id)
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(EmployeeAllowance::query()->whereKey($row->id)->exists())->toBeFalse();
});

it('forbids hr from destroying an allowance', function () {
    $user = authAllowanceAs('hr');
    [$staff, $profile] = allowanceStaffAndProfile();

    $row = EmployeeAllowance::factory()->create([
        'employee_profile_id' => $profile->id,
    ]);

    $this->actingAs($user)
        ->delete('/employees/'.$staff->id.'/allowances/'.$row->id)
        ->assertForbidden();

    expect(EmployeeAllowance::query()->whereKey($row->id)->exists())->toBeTrue();
});
