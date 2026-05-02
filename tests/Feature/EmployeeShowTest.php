<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Show route renders the LMS identity for a single staff plus the (optional)
 * payroll profile. Hits the live LMS DB on the `lms` connection (read-only)
 * for identity, and the in-memory sqlite default connection for profile data.
 */

function authShowAs(string $payrollRole): User
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
    $staff = Staff::query()->first();

    $this->get('/employees/'.(int) $staff->id)->assertRedirect('/login');
});

it('forbids the employee role', function () {
    $user = authShowAs('employee');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    $this->actingAs($user)
        ->get('/employees/'.(int) $staff->id)
        ->assertForbidden();
});

it('renders the show page for super-admin', function () {
    $user = authShowAs('super-admin');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    // Frontend page (employees/show.tsx) is part of Step 3 — skip the file
    // existence check via the second `component()` argument.
    $this->actingAs($user)
        ->get('/employees/'.(int) $staff->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/show')
            ->has('employee')
            ->where('employee.lms_staff_id', (int) $staff->id)
        );
});

it('renders the show page for payroll-officer', function () {
    $user = authShowAs('payroll-officer');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    $this->actingAs($user)
        ->get('/employees/'.(int) $staff->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('employees/show'));
});

it('renders the show page for hr', function () {
    $user = authShowAs('hr');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    $this->actingAs($user)
        ->get('/employees/'.(int) $staff->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('employees/show'));
});

it('renders the show page for auditor', function () {
    $user = authShowAs('auditor');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    $this->actingAs($user)
        ->get('/employees/'.(int) $staff->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('employees/show'));
});

it('returns 404 for an unknown staff id', function () {
    $user = authShowAs('super-admin');

    $this->actingAs($user)
        ->get('/employees/9999999')
        ->assertNotFound();
});

it('returns 404 for a staff id whose role is not in the payroll allowlist', function () {
    $user = authShowAs('super-admin');

    // All seeded staff have role_id in {1, 4, 5}. Narrow the allowlist so a
    // role_id=4 staff falls outside it and the show route returns 404.
    config(['payroll.employee_role_allowlist' => [1]]);

    $staff = Staff::query()->where('role_id', 4)->first();
    expect($staff)->not->toBeNull();

    $this->actingAs($user)
        ->get('/employees/'.(int) $staff->id)
        ->assertNotFound();
});

it('renders LMS identity even when no payroll profile exists', function () {
    $user = authShowAs('super-admin');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();

    // Confirm there's no profile row for this staff.
    expect(EmployeeProfile::query()->where('lms_staff_id', $staff->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->get('/employees/'.(int) $staff->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/show')
            ->where('employee.lms_staff_id', (int) $staff->id)
            ->where('employee.profile', null)
            ->where('employee.full_name', $staff->full_name)
        );
});

it('renders the full profile when one exists', function () {
    $user = authShowAs('super-admin');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();

    EmployeeProfile::query()->create([
        'lms_staff_id' => (int) $staff->id,
        'employment_classification' => 'regular',
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 7_500_000,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get('/employees/'.(int) $staff->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/show')
            ->where('employee.lms_staff_id', (int) $staff->id)
            ->where('employee.profile.basic_salary_centavos', 7_500_000)
            ->where('employee.profile.employment_classification', 'regular')
            ->where('employee.profile.pay_frequency', 'monthly')
            ->where('employee.profile.is_active', true)
        );
});
