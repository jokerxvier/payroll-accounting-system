<?php

declare(strict_types=1);

use App\Jobs\Payroll\ComputeEmployeePayslipJob;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\Pas\StatutoryContribution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/*
 * Pin the admin payroll-run controller surface (Phase 3 W9 Stage C):
 *   - super-admin can list/create/show
 *   - other roles get 403
 *   - store dispatches GeneratePayrollRunAction (Bus::assertBatched)
 *   - show payload exposes the run + payslip rows + computing-state progress
 */

function authPayrollRunsAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/**
 * Payroll-native platform admin: NULL lms_user_id + platform-admin role.
 * Gate::before auto-grants every ability, so this user is the regression
 * surface for the status-aware `can` flags — the buttons must still hide on
 * terminal-state runs even though the gate short-circuits to true.
 */
function platformAdminForPayrollRuns(): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->withoutLmsMirror()->create();
    $user->syncRoles(['platform-admin']);

    return $user->fresh() ?? $user;
}

function seedFourPhContributionsForController(): void
{
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->philhealth()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->pagibig()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
}

it('renders the index for a super-admin', function () {
    $user = authPayrollRunsAs('super-admin');
    PayrollRun::factory()->count(2)->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/payroll-runs/index')
                ->where('can.create', true)
                ->has('runs', 2),
        );
});

it('allows payroll-officer and hr to view the index (maker roles)', function (string $role) {
    $user = authPayrollRunsAs($role);

    $this->actingAs($user)
        ->get('/admin/payroll-runs')
        ->assertOk();
})->with(['payroll-officer', 'hr']);

it('forbids the index for auditor and employee', function (string $role) {
    $user = authPayrollRunsAs($role);

    $this->actingAs($user)
        ->get('/admin/payroll-runs')
        ->assertForbidden();
})->with(['auditor', 'employee']);

it('renders the create form with open periods', function () {
    $user = authPayrollRunsAs('super-admin');
    PayPeriod::factory()->monthly(2026, 5)->open()->create();
    PayPeriod::factory()->monthly(2026, 6)->create(); // draft — must not appear

    $this->actingAs($user)
        ->get('/admin/payroll-runs/create')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/payroll-runs/create')
                ->has('periods', 1),
        );
});

it('store dispatches one job per active employee and lands a computing run', function () {
    seedFourPhContributionsForController();
    Bus::fake();

    $user = authPayrollRunsAs('super-admin');
    EmployeeProfile::factory()->count(2)->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000,
    ]);
    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();

    $response = $this->actingAs($user)
        ->from('/admin/payroll-runs/create')
        ->post('/admin/payroll-runs', [
            'pay_period_id' => $period->id,
        ]);

    $run = PayrollRun::query()->latest('id')->firstOrFail();

    $response
        ->assertRedirect('/admin/payroll-runs/'.$run->id)
        ->assertSessionHas('success');

    expect($run->status)->toBe(PayrollRun::STATUS_COMPUTING)
        ->and($run->total_employees)->toBe(2);

    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 2
            && $batch->jobs->every(fn ($j): bool => $j instanceof ComputeEmployeePayslipJob);
    });
});

it('rejects store when the pay_period is not open', function () {
    $user = authPayrollRunsAs('super-admin');
    $period = PayPeriod::factory()->monthly(2026, 5)->create(); // draft

    $this->actingAs($user)
        ->from('/admin/payroll-runs/create')
        ->post('/admin/payroll-runs', [
            'pay_period_id' => $period->id,
        ])
        ->assertRedirect('/admin/payroll-runs/create')
        ->assertSessionHasErrors('pay_period_id');
});

it('shows a run with payslips and progress props', function () {
    $user = authPayrollRunsAs('super-admin');
    $run = PayrollRun::factory()->computed()->create([
        'total_employees' => 2,
    ]);
    Payslip::factory()->count(2)->for($run, 'payrollRun')->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/payroll-runs/show')
                ->where('run.id', $run->id)
                ->where('progress.persisted_payslips', 2)
                ->where('progress.total_employees', 2)
                ->has('payslips', 2),
        );
});

it('allows payroll-officer and hr to view a run (maker roles)', function (string $role) {
    $user = authPayrollRunsAs($role);
    $run = PayrollRun::factory()->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertOk();
})->with(['payroll-officer', 'hr']);

it('forbids show for auditor and employee', function (string $role) {
    $user = authPayrollRunsAs($role);
    $run = PayrollRun::factory()->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertForbidden();
})->with(['auditor', 'employee']);

it('hides all action buttons on a posted run for a platform-admin', function () {
    $user = platformAdminForPayrollRuns();
    $run = PayrollRun::factory()->create(['status' => PayrollRun::STATUS_POSTED]);

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/payroll-runs/show')
                ->has('can')
                ->where('can.submit', false)
                ->where('can.approve', false)
                ->where('can.post', false)
                ->where('can.void', false),
        );
});

it('hides all action buttons on a voided run for a platform-admin', function () {
    $user = platformAdminForPayrollRuns();
    $run = PayrollRun::factory()->voided()->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('can.submit', false)
                ->where('can.approve', false)
                ->where('can.post', false)
                ->where('can.void', false),
        );
});

it('shows only submit (and void) on a computed run for a platform-admin', function () {
    $user = platformAdminForPayrollRuns();
    $run = PayrollRun::factory()->computed()->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('can.submit', true)
                ->where('can.approve', false)
                ->where('can.post', false)
                ->where('can.void', true),
        );
});

it('shows only approve (and void) on a pending_approval run for a platform-admin', function () {
    $user = platformAdminForPayrollRuns();
    $run = PayrollRun::factory()->create(['status' => PayrollRun::STATUS_PENDING_APPROVAL]);

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('can.submit', false)
                ->where('can.approve', true)
                ->where('can.post', false)
                ->where('can.void', true),
        );
});

it('shows only post (and void) on an approved run for a platform-admin', function () {
    $user = platformAdminForPayrollRuns();
    $run = PayrollRun::factory()->approved()->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('can.submit', false)
                ->where('can.approve', false)
                ->where('can.post', true)
                ->where('can.void', true),
        );
});
