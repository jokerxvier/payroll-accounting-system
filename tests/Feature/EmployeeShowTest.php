<?php

declare(strict_types=1);

use App\Models\Lms\Staff;
use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
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

it('passes deduction / allowance / loan / adjustment collections via Inertia props', function () {
    // Verifies the Week 7 Chunk 6 prop additions: when an employee has rows in
    // each of the four subscription tables, the show response includes them
    // as collections with the eager-loaded catalog reference. Empty when no
    // profile exists is covered by the prior "renders LMS identity even when
    // no payroll profile exists" test (which doesn't assert these props but
    // also does not error — confirmed via the controller's null-collection
    // short-circuit).
    $user = authShowAs('super-admin');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();

    $profile = EmployeeProfile::query()->create([
        'lms_staff_id' => (int) $staff->id,
        'employment_classification' => 'regular',
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 5_000_000,
        'is_active' => true,
    ]);

    $deductionType = DeductionType::factory()->hmo()->create();
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $profile->id,
        'deduction_type_id' => $deductionType->id,
        'amount_centavos' => 200_000,
    ]);

    $allowance = Allowance::factory()->riceSubsidy()->create();
    EmployeeAllowance::factory()->create([
        'employee_profile_id' => $profile->id,
        'allowance_id' => $allowance->id,
        'amount_centavos' => 200_000,
    ]);

    EmployeeLoan::factory()->create([
        'employee_profile_id' => $profile->id,
        'code' => 'LN-SHOW-001',
        'principal_centavos' => 6_000_000,
        'outstanding_balance_centavos' => 4_500_000,
        'monthly_amortization_centavos' => 500_000,
    ]);

    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
        'kind' => PayrollAdjustment::KIND_ADDITION,
        'is_taxable' => true,
        'amount_centavos' => 1_000_000,
        'applies_on' => now()->addDays(7)->toDateString(), // future = "pending"
        'label' => 'Upcoming bonus',
    ]);

    // A past-dated adjustment must NOT be in pendingAdjustments.
    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
        'kind' => PayrollAdjustment::KIND_ADDITION,
        'amount_centavos' => 100_000,
        'applies_on' => now()->subMonths(3)->toDateString(),
        'label' => 'Past bonus',
    ]);

    $this->actingAs($user)
        ->get('/employees/'.(int) $staff->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/show')
            ->has('deductions', 1)
            ->where('deductions.0.deduction_type.code', 'hmo')
            ->has('allowances', 1)
            ->where('allowances.0.allowance.code', 'rice_subsidy')
            ->has('loans', 1)
            ->where('loans.0.code', 'LN-SHOW-001')
            ->has('pendingAdjustments', 1)
            ->where('pendingAdjustments.0.label', 'Upcoming bonus')
            ->has('deductionTypeOptions')
            ->has('allowanceOptions')
        );
});

it('passes empty collections when the staff has no payroll profile', function () {
    $user = authShowAs('super-admin');

    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    expect($staff)->not->toBeNull();
    expect(EmployeeProfile::query()->where('lms_staff_id', $staff->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->get('/employees/'.(int) $staff->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employees/show')
            ->has('deductions', 0)
            ->has('allowances', 0)
            ->has('loans', 0)
            ->has('pendingAdjustments', 0)
        );
});
