<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\AuditLog;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * PATCH /employees/{staffId}/profile must:
 *   - validate input strictly
 *   - emit exactly one audit log row per save (Auditable trait fires on dirty)
 *   - never mutate any LMS-owned table (sm_staffs, users, roles, ...)
 *   - respect the policy (auditor read-only, employee blocked)
 */

function authUpdateAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function profileForFirstAllowlistedStaff(): EmployeeProfile
{
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();

    return EmployeeProfile::query()->create([
        'lms_staff_id' => (int) $staff->id,
        'employment_classification' => 'regular',
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 5_000_000,
        'is_active' => true,
    ]);
}

it('rejects a negative basic_salary_centavos', function () {
    $user = authUpdateAs('super-admin');
    $profile = profileForFirstAllowlistedStaff();

    $this->actingAs($user)
        ->patch('/employees/'.$profile->lms_staff_id.'/profile', [
            'basic_salary_centavos' => -1,
        ])
        ->assertSessionHasErrors('basic_salary_centavos');
});

it('rejects an unknown employment_classification', function () {
    $user = authUpdateAs('super-admin');
    $profile = profileForFirstAllowlistedStaff();

    $this->actingAs($user)
        ->patch('/employees/'.$profile->lms_staff_id.'/profile', [
            'employment_classification' => 'freelancer',
        ])
        ->assertSessionHasErrors('employment_classification');
});

it('rejects an unknown pay_frequency', function () {
    $user = authUpdateAs('super-admin');
    $profile = profileForFirstAllowlistedStaff();

    $this->actingAs($user)
        ->patch('/employees/'.$profile->lms_staff_id.'/profile', [
            'pay_frequency' => 'weekly',
        ])
        ->assertSessionHasErrors('pay_frequency');
});

it('flips is_active from true to false on PATCH', function () {
    $user = authUpdateAs('super-admin');
    $profile = profileForFirstAllowlistedStaff();
    expect($profile->is_active)->toBeTrue();

    $this->actingAs($user)
        ->from('/employees')
        ->patch('/employees/'.$profile->lms_staff_id.'/profile', [
            'is_active' => false,
        ])
        ->assertRedirect('/employees');

    $profile->refresh();
    expect($profile->is_active)->toBeFalse();
});

it('flips is_active when the full edit-sheet payload is submitted', function () {
    // Mimics what the EmployeeEditSheet sends — every field present, is_active toggled to false.
    $user = authUpdateAs('super-admin');
    $profile = profileForFirstAllowlistedStaff();
    expect($profile->is_active)->toBeTrue();

    $this->actingAs($user)
        ->from('/employees/'.$profile->lms_staff_id)
        ->patch('/employees/'.$profile->lms_staff_id.'/profile', [
            'basic_salary_centavos' => 5_000_000,
            'employment_classification' => 'regular',
            'pay_frequency' => 'monthly',
            'tax_status' => '',
            'tin' => '',
            'sss_number' => '',
            'philhealth_number' => '',
            'pagibig_number' => '',
            'bank_name' => '',
            'bank_account_number' => '',
            'bank_account_name' => '',
            'date_hired' => '',
            'date_terminated' => '',
            'is_active' => false,
        ])
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect('/employees/'.$profile->lms_staff_id);

    $profile->refresh();
    expect($profile->is_active)->toBeFalse();
});

it('writes exactly one audit row for a single-field update', function () {
    $user = authUpdateAs('super-admin');
    $profile = profileForFirstAllowlistedStaff();

    // The factory create() above already wrote a `created` audit row. Pin
    // the baseline count so we only assert on the new `updated` row.
    $auditBaseline = AuditLog::query()
        ->where('auditable_type', EmployeeProfile::class)
        ->where('auditable_id', $profile->id)
        ->count();
    expect($auditBaseline)->toBe(1); // one created row

    $this->actingAs($user)
        ->from('/employees')
        ->patch('/employees/'.$profile->lms_staff_id.'/profile', [
            'basic_salary_centavos' => 9_999_900,
        ])
        ->assertRedirect('/employees');

    $rows = AuditLog::query()
        ->where('auditable_type', EmployeeProfile::class)
        ->where('auditable_id', $profile->id)
        ->where('action', 'updated')
        ->get();

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect(array_keys($row->before))->toBe(['basic_salary_centavos'])
        ->and(array_keys($row->after))->toBe(['basic_salary_centavos'])
        ->and((int) $row->before['basic_salary_centavos'])->toBe(5_000_000)
        ->and((int) $row->after['basic_salary_centavos'])->toBe(9_999_900);
});

it('writes exactly one audit row for a multi-field update', function () {
    $user = authUpdateAs('payroll-officer');
    $profile = profileForFirstAllowlistedStaff();

    $this->actingAs($user)
        ->from('/employees/'.$profile->lms_staff_id)
        ->patch('/employees/'.$profile->lms_staff_id.'/profile', [
            'basic_salary_centavos' => 8_000_000,
            'employment_classification' => 'probationary',
        ])
        ->assertRedirect('/employees/'.$profile->lms_staff_id);

    $rows = AuditLog::query()
        ->where('auditable_type', EmployeeProfile::class)
        ->where('auditable_id', $profile->id)
        ->where('action', 'updated')
        ->get();

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    $beforeKeys = array_keys($row->before);
    $afterKeys = array_keys($row->after);
    sort($beforeKeys);
    sort($afterKeys);

    expect($beforeKeys)->toBe(['basic_salary_centavos', 'employment_classification'])
        ->and($afterKeys)->toBe(['basic_salary_centavos', 'employment_classification'])
        ->and((int) $row->before['basic_salary_centavos'])->toBe(5_000_000)
        ->and((int) $row->after['basic_salary_centavos'])->toBe(8_000_000)
        ->and($row->before['employment_classification'])->toBe('regular')
        ->and($row->after['employment_classification'])->toBe('probationary');
});

it('does not mutate any LMS-owned table when updating a profile', function () {
    $user = authUpdateAs('super-admin');
    $profile = profileForFirstAllowlistedStaff();

    $usersCount = (int) DB::connection('lms')->table('users')->count();
    $staffsCount = (int) DB::connection('lms')->table('sm_staffs')->count();
    $rolesCount = (int) DB::connection('lms')->table('roles')->count();
    $deptCount = (int) DB::connection('lms')->table('sm_human_departments')->count();
    $desigCount = (int) DB::connection('lms')->table('sm_designations')->count();

    $this->actingAs($user)
        ->from('/employees')
        ->patch('/employees/'.$profile->lms_staff_id.'/profile', [
            'basic_salary_centavos' => 6_500_000,
            'tin' => '123-456-789-000',
            'bank_account_number' => '0001234567890',
        ])
        ->assertRedirect('/employees');

    expect((int) DB::connection('lms')->table('users')->count())->toBe($usersCount)
        ->and((int) DB::connection('lms')->table('sm_staffs')->count())->toBe($staffsCount)
        ->and((int) DB::connection('lms')->table('roles')->count())->toBe($rolesCount)
        ->and((int) DB::connection('lms')->table('sm_human_departments')->count())->toBe($deptCount)
        ->and((int) DB::connection('lms')->table('sm_designations')->count())->toBe($desigCount);
});

it('forbids the auditor role on update', function () {
    $user = authUpdateAs('auditor');
    $profile = profileForFirstAllowlistedStaff();

    $this->actingAs($user)
        ->patch('/employees/'.$profile->lms_staff_id.'/profile', [
            'basic_salary_centavos' => 9_999_900,
        ])
        ->assertForbidden();
});

it('forbids the employee role on update', function () {
    $user = authUpdateAs('employee');
    $profile = profileForFirstAllowlistedStaff();

    $this->actingAs($user)
        ->patch('/employees/'.$profile->lms_staff_id.'/profile', [
            'basic_salary_centavos' => 9_999_900,
        ])
        ->assertForbidden();
});

it('returns 404 when the profile does not exist', function () {
    $user = authUpdateAs('super-admin');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();
    expect(EmployeeProfile::query()->where('lms_staff_id', $staff->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->patch('/employees/'.(int) $staff->id.'/profile', [
            'basic_salary_centavos' => 1_000_000,
        ])
        ->assertNotFound();
});
