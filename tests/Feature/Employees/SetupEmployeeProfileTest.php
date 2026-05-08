<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * "Set up profile" affordance on /employees — POST /employees/{staffId}/profile
 * with the four create-time required fields (basic_salary_centavos,
 * employment_classification, pay_frequency, is_active).
 *
 * The endpoint already existed for the no-payload create flow (covered by
 * tests/Feature/EmployeeProfileStoreTest.php). This file pins the new
 * EmployeeProfileStoreRequest validation contract that backs the index-page
 * sheet, plus tenant scoping.
 */

function authSetupAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function firstAllowlistedStaffId(): int
{
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();

    return (int) $staff->id;
}

it('payroll-officer can set up a profile with a valid payload', function (): void {
    $user = authSetupAs('payroll-officer');
    $staffId = firstAllowlistedStaffId();

    expect(EmployeeProfile::query()->where('lms_staff_id', $staffId)->exists())->toBeFalse();

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/'.$staffId.'/profile', [
            'basic_salary_centavos' => 4_500_000,
            'employment_classification' => 'probationary',
            'pay_frequency' => 'semi_monthly',
            'is_active' => true,
        ])
        ->assertRedirect('/employees')
        ->assertSessionHas('success');

    $profile = EmployeeProfile::query()->where('lms_staff_id', $staffId)->first();

    expect($profile)->not->toBeNull()
        ->and((int) $profile->basic_salary_centavos)->toBe(4_500_000)
        ->and($profile->employment_classification)->toBe('probationary')
        ->and($profile->pay_frequency)->toBe('semi_monthly')
        ->and($profile->is_active)->toBeTrue();
});

it('re-posting for a staff that already has a profile is a no-op', function (): void {
    $user = authSetupAs('hr');
    $staffId = firstAllowlistedStaffId();

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/'.$staffId.'/profile', [
            'basic_salary_centavos' => 3_000_000,
            'employment_classification' => 'regular',
            'pay_frequency' => 'monthly',
            'is_active' => true,
        ])
        ->assertRedirect('/employees');

    expect(EmployeeProfile::query()->where('lms_staff_id', $staffId)->count())->toBe(1);

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/'.$staffId.'/profile', [
            'basic_salary_centavos' => 9_999_900,
            'employment_classification' => 'contractual',
            'pay_frequency' => 'semi_monthly',
            'is_active' => false,
        ])
        ->assertRedirect('/employees');

    expect(EmployeeProfile::query()->where('lms_staff_id', $staffId)->count())->toBe(1);

    // The second POST's payload was ignored — firstOrCreate keys on
    // lms_staff_id, so the original values stand.
    $profile = EmployeeProfile::query()->where('lms_staff_id', $staffId)->firstOrFail();
    expect((int) $profile->basic_salary_centavos)->toBe(3_000_000)
        ->and($profile->employment_classification)->toBe('regular')
        ->and($profile->pay_frequency)->toBe('monthly')
        ->and($profile->is_active)->toBeTrue();
});

it('rejects a payload missing every required field', function (): void {
    $user = authSetupAs('super-admin');
    $staffId = firstAllowlistedStaffId();

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/'.$staffId.'/profile', [])
        ->assertSessionHasErrors([
            'basic_salary_centavos',
            'employment_classification',
            'pay_frequency',
            'is_active',
        ]);

    expect(EmployeeProfile::query()->where('lms_staff_id', $staffId)->exists())->toBeFalse();
});

it('rejects a negative basic salary', function (): void {
    $user = authSetupAs('super-admin');
    $staffId = firstAllowlistedStaffId();

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/'.$staffId.'/profile', [
            'basic_salary_centavos' => -1,
            'employment_classification' => 'regular',
            'pay_frequency' => 'monthly',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('basic_salary_centavos');

    expect(EmployeeProfile::query()->where('lms_staff_id', $staffId)->exists())->toBeFalse();
});

it('rejects an unknown employment classification', function (): void {
    $user = authSetupAs('super-admin');
    $staffId = firstAllowlistedStaffId();

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/'.$staffId.'/profile', [
            'basic_salary_centavos' => 1_000_000,
            'employment_classification' => 'freelancer',
            'pay_frequency' => 'monthly',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('employment_classification');

    expect(EmployeeProfile::query()->where('lms_staff_id', $staffId)->exists())->toBeFalse();
});

it('rejects an unknown pay frequency', function (): void {
    $user = authSetupAs('super-admin');
    $staffId = firstAllowlistedStaffId();

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/'.$staffId.'/profile', [
            'basic_salary_centavos' => 1_000_000,
            'employment_classification' => 'regular',
            'pay_frequency' => 'weekly',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('pay_frequency');

    expect(EmployeeProfile::query()->where('lms_staff_id', $staffId)->exists())->toBeFalse();
});

it('forbids the auditor role on setup', function (): void {
    $user = authSetupAs('auditor');
    $staffId = firstAllowlistedStaffId();

    $this->actingAs($user)
        ->post('/employees/'.$staffId.'/profile', [
            'basic_salary_centavos' => 1_000_000,
            'employment_classification' => 'regular',
            'pay_frequency' => 'monthly',
            'is_active' => true,
        ])
        ->assertForbidden();

    expect(EmployeeProfile::query()->where('lms_staff_id', $staffId)->exists())->toBeFalse();
});

it('forbids the employee role on setup', function (): void {
    $user = authSetupAs('employee');
    $staffId = firstAllowlistedStaffId();

    $this->actingAs($user)
        ->post('/employees/'.$staffId.'/profile', [
            'basic_salary_centavos' => 1_000_000,
            'employment_classification' => 'regular',
            'pay_frequency' => 'monthly',
            'is_active' => true,
        ])
        ->assertForbidden();

    expect(EmployeeProfile::query()->where('lms_staff_id', $staffId)->exists())->toBeFalse();
});

it('auto-fills school_id from the current tenant on setup', function (): void {
    $user = authSetupAs('super-admin');
    $staffId = firstAllowlistedStaffId();

    // Switch to a freshly-created tenant before posting so the
    // BelongsToTenant trait fills its school_id, not the default's.
    $other = School::factory()->create(['slug' => 'setup-other']);
    $other->makeCurrent();

    $this->actingAs($user)
        ->from('/employees')
        ->post('/employees/'.$staffId.'/profile', [
            'basic_salary_centavos' => 2_500_000,
            'employment_classification' => 'regular',
            'pay_frequency' => 'monthly',
            'is_active' => true,
        ])
        ->assertRedirect('/employees');

    // Use withoutGlobalScopes so the assertion doesn't get filtered by the
    // current-tenant scope; we want to read the row's school_id directly.
    $profile = EmployeeProfile::query()
        ->withoutGlobalScopes()
        ->where('lms_staff_id', $staffId)
        ->firstOrFail();

    expect($profile->school_id)->toBe($other->getKey());
});
