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
                ->has('runs.data', 2)
                ->where('runs.per_page', 25)
                ->where('runs.total', 2),
        );
});

it('paginates the index and lists the latest run first', function () {
    $user = authPayrollRunsAs('super-admin');
    PayrollRun::factory()->count(26)->create();
    $latest = PayrollRun::query()->max('id');

    $this->actingAs($user)
        ->get('/admin/payroll-runs')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('runs.data', 25)               // first page is full
                ->where('runs.current_page', 1)
                ->where('runs.last_page', 2)
                ->where('runs.total', 26)
                ->where('runs.data.0.id', $latest),  // descending: newest first
        );

    // Second page holds the remaining run.
    $this->actingAs($user)
        ->get('/admin/payroll-runs?page=2')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('runs.data', 1)
                ->where('runs.current_page', 2),
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
                ->has('periods', 1)
                ->has('active_employee_count'),
        );
});

it('reports the active employee count on the create form', function () {
    $user = authPayrollRunsAs('super-admin');
    PayPeriod::factory()->monthly(2026, 5)->open()->create();
    EmployeeProfile::factory()->count(3)->create(['is_active' => true]);
    EmployeeProfile::factory()->create(['is_active' => false]); // excluded

    $this->actingAs($user)
        ->get('/admin/payroll-runs/create')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->where('active_employee_count', 3),
        );
});

it('excludes finished (approved or posted) periods from the create form', function () {
    $user = authPayrollRunsAs('super-admin');

    // Ordered start_date desc → May (index 0) before April (index 1).
    $may = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    $april = PayPeriod::factory()->monthly(2026, 4)->open()->create();

    // May is finished (approved run); April is still open with no run.
    PayrollRun::factory()->approved()->create(['pay_period_id' => $may->id]);

    $this->actingAs($user)
        ->get('/admin/payroll-runs/create')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/payroll-runs/create')
                ->has('periods', 1)
                ->where('periods.0.id', $april->id)
                ->where('periods.0.locked_by_run', null),
        );
});

it('does not flag create-form periods whose only run is voided or computed', function () {
    $user = authPayrollRunsAs('super-admin');

    $voidedPeriod = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    $computedPeriod = PayPeriod::factory()->monthly(2026, 4)->open()->create();

    PayrollRun::factory()->voided()->create(['pay_period_id' => $voidedPeriod->id]);
    PayrollRun::factory()->computed()->create(['pay_period_id' => $computedPeriod->id]);

    $this->actingAs($user)
        ->get('/admin/payroll-runs/create')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('periods.0.locked_by_run', null)
                ->where('periods.1.locked_by_run', null),
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
                ->has('payslips.data', 2)
                ->where('payslips.per_page', 25)
                ->where('payslips.total', 2),
        );
});

it('paginates payslips on the run detail while keeping full progress', function () {
    $user = authPayrollRunsAs('super-admin');
    $run = PayrollRun::factory()->computed()->create([
        'total_employees' => 26,
    ]);
    Payslip::factory()->count(26)->for($run, 'payrollRun')->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('payslips.data', 25)               // first page is full
                ->where('payslips.current_page', 1)
                ->where('payslips.last_page', 2)
                ->where('payslips.total', 26)
                // Progress spans every page, not just the 25 on this one.
                ->where('progress.persisted_payslips', 26),
        );

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'?page=2')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('payslips.data', 1)
                ->where('payslips.current_page', 2)
                ->where('progress.persisted_payslips', 26),
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

it('shows post, submit, void, and delete on a computed run for a platform-admin', function () {
    $user = platformAdminForPayrollRuns();
    $run = PayrollRun::factory()->computed()->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('can.submit', true)
                ->where('can.approve', false)
                // DEMO: computed is directly postable now.
                ->where('can.post', true)
                ->where('can.void', true)
                ->where('can.delete', true),
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
