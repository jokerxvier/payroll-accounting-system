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

it('returns Week 7 subscription rows alongside the profile', function () {
    $user = authProfileJsonAs('payroll-officer');
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

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
        'code' => 'LN-JSON-001',
        'principal_centavos' => 6_000_000,
        'outstanding_balance_centavos' => 4_500_000,
        'monthly_amortization_centavos' => 500_000,
    ]);

    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
        'kind' => PayrollAdjustment::KIND_ADDITION,
        'is_taxable' => true,
        'amount_centavos' => 1_000_000,
        'applies_on' => now()->addDays(7)->toDateString(),
        'label' => 'Upcoming bonus',
    ]);

    // Past adjustment must NOT appear in pending list — mirrors show().
    PayrollAdjustment::factory()->create([
        'employee_profile_id' => $profile->id,
        'kind' => PayrollAdjustment::KIND_ADDITION,
        'amount_centavos' => 100_000,
        'applies_on' => now()->subMonths(3)->toDateString(),
        'label' => 'Past bonus',
    ]);

    $response = $this->actingAs($user)
        ->getJson('/employees/'.(int) $staff->id.'/profile/json')
        ->assertOk()
        ->assertJsonPath('profile.id', $profile->id)
        ->assertJsonCount(1, 'deductions')
        ->assertJsonPath('deductions.0.deduction_type.code', 'hmo')
        ->assertJsonCount(1, 'allowances')
        ->assertJsonPath('allowances.0.allowance.code', 'rice_subsidy')
        ->assertJsonCount(1, 'loans')
        ->assertJsonPath('loans.0.code', 'LN-JSON-001')
        ->assertJsonCount(1, 'pendingAdjustments')
        ->assertJsonPath('pendingAdjustments.0.label', 'Upcoming bonus');

    // Catalog options are surfaced for the create/edit sheets so the
    // directory editor never has to hit a separate endpoint.
    $payload = $response->json();
    expect($payload)->toHaveKeys(['deductionTypeOptions', 'allowanceOptions']);
    expect($payload['deductionTypeOptions'])->toBeArray()->not->toBeEmpty();
    expect($payload['allowanceOptions'])->toBeArray()->not->toBeEmpty();
});

it('returns empty Week 7 collections when no payroll profile exists', function () {
    $user = authProfileJsonAs('payroll-officer');
    $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();

    EmployeeProfile::query()->where('lms_staff_id', (int) $staff->id)->delete();

    // Seed catalog rows so the option lists are non-empty even without a
    // profile — the editor still needs them to populate the create sheet.
    DeductionType::factory()->hmo()->create();
    Allowance::factory()->riceSubsidy()->create();

    $this->actingAs($user)
        ->getJson('/employees/'.(int) $staff->id.'/profile/json')
        ->assertOk()
        ->assertJsonPath('profile', null)
        ->assertJsonPath('deductions', [])
        ->assertJsonPath('allowances', [])
        ->assertJsonPath('loans', [])
        ->assertJsonPath('pendingAdjustments', [])
        ->assertJsonCount(1, 'deductionTypeOptions')
        ->assertJsonCount(1, 'allowanceOptions');
});
