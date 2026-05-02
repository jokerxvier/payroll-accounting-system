<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function authProfileJsonAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

it('requires authentication', function () {
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    $this->getJson('/employees/'.(int) $staff->id.'/profile/json')
        ->assertUnauthorized();
});

it('forbids the employee role', function () {
    $user = authProfileJsonAs('employee');
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    $this->actingAs($user)
        ->getJson('/employees/'.(int) $staff->id.'/profile/json')
        ->assertForbidden();
});

it('returns the profile and minimal employee identity', function () {
    $user = authProfileJsonAs('payroll-officer');
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    $profile = EmployeeProfile::factory()->create([
        'lms_staff_id' => (int) $staff->id,
        'basic_salary_centavos' => 5_000_000,
        'employment_classification' => 'regular',
    ]);

    $this->actingAs($user)
        ->getJson('/employees/'.(int) $staff->id.'/profile/json')
        ->assertOk()
        ->assertJsonPath('employee.lms_staff_id', (int) $staff->id)
        ->assertJsonPath('profile.id', $profile->id)
        ->assertJsonPath('profile.basic_salary_centavos', 5_000_000)
        ->assertJsonPath('profile.employment_classification', 'regular');
});

it('returns null profile when no payroll profile exists yet', function () {
    $user = authProfileJsonAs('payroll-officer');
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    EmployeeProfile::query()->where('lms_staff_id', (int) $staff->id)->delete();

    $this->actingAs($user)
        ->getJson('/employees/'.(int) $staff->id.'/profile/json')
        ->assertOk()
        ->assertJsonPath('profile', null)
        ->assertJsonPath('employee.lms_staff_id', (int) $staff->id);
});

it('404s on unknown staff_id', function () {
    $user = authProfileJsonAs('payroll-officer');

    $this->actingAs($user)
        ->getJson('/employees/99999999/profile/json')
        ->assertNotFound();
});

it('404s on staff_id outside the role allowlist', function () {
    $user = authProfileJsonAs('payroll-officer');
    $staff = Staff::query()->whereNotIn('role_id', [1, 4, 5])->first();

    if ($staff === null) {
        $this->markTestSkipped('No LMS staff outside allowlist available.');
    }

    $this->actingAs($user)
        ->getJson('/employees/'.(int) $staff->id.'/profile/json')
        ->assertNotFound();
});
