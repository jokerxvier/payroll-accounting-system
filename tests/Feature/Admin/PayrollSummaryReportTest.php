<?php

declare(strict_types=1);

use App\Exports\PayrollSummaryReportExport;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

function authReportsAs(string $role): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('renders the payroll-summary report for allowed roles', function (string $role) {
    $user = authReportsAs($role);

    // Two periods: one inside the requested range, one outside.
    $inside = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    $outside = PayPeriod::factory()->monthly(2026, 1)->open()->create();

    $insideRun = PayrollRun::factory()->computed()->create([
        'pay_period_id' => $inside->id,
        'total_employee_deductions_centavos' => 1_000_000,
        'total_employer_contributions_centavos' => 500_000,
        'total_net_pay_centavos' => 4_000_000,
    ]);
    Payslip::factory()->count(2)->for($insideRun, 'payrollRun')->create([
        'gross_pay_centavos' => 2_500_000,
    ]);

    PayrollRun::factory()->computed()->create(['pay_period_id' => $outside->id]);

    $this->actingAs($user)
        ->get('/admin/reports/payroll-summary?from=2026-05-01&to=2026-05-31')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/reports/payroll-summary')
                ->where('filters.from', '2026-05-01')
                ->where('filters.to', '2026-05-31')
                ->has('rows', 1)
                ->where('rows.0.run_id', $insideRun->id)
                ->where('rows.0.employee_count', 2)
                ->where('rows.0.gross_pay_centavos', 5_000_000)
                ->where('totals.gross_pay_centavos', 5_000_000),
        );
})->with(['super-admin', 'payroll-officer', 'hr']);

it('forbids the report for roles that should not see analytics', function (string $role) {
    $user = authReportsAs($role);

    $this->actingAs($user)
        ->get('/admin/reports/payroll-summary')
        ->assertForbidden();
})->with(['auditor', 'employee']);

it('excludes voided runs from the summary', function () {
    $user = authReportsAs('super-admin');
    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();

    PayrollRun::factory()->voided()->create([
        'pay_period_id' => $period->id,
        'total_net_pay_centavos' => 9_999_999,
    ]);
    PayrollRun::factory()->computed()->create([
        'pay_period_id' => $period->id,
        'total_net_pay_centavos' => 1_234_567,
    ]);

    $this->actingAs($user)
        ->get('/admin/reports/payroll-summary?from=2026-05-01&to=2026-05-31')
        ->assertInertia(
            fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.total_net_pay_centavos', 1_234_567),
        );
});

it('flips an inverted date range gracefully', function () {
    $user = authReportsAs('super-admin');

    $this->actingAs($user)
        ->get('/admin/reports/payroll-summary?from=2026-05-31&to=2026-05-01')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('filters.from', '2026-05-01')
                ->where('filters.to', '2026-05-31'),
        );
});

it('downloads the Excel export via Excel::fake', function () {
    Excel::fake();
    $user = authReportsAs('super-admin');
    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    PayrollRun::factory()->computed()->create(['pay_period_id' => $period->id]);

    $this->actingAs($user)
        ->get('/admin/reports/payroll-summary/export?from=2026-05-01&to=2026-05-31')
        ->assertOk();

    Excel::assertDownloaded(
        'payroll-summary_2026-05-01_2026-05-31.xlsx',
        fn ($export): bool => $export instanceof PayrollSummaryReportExport,
    );
});

it('forbids the Excel export for non-report roles', function (string $role) {
    Excel::fake();
    $user = authReportsAs($role);

    $this->actingAs($user)
        ->get('/admin/reports/payroll-summary/export')
        ->assertForbidden();
})->with(['auditor', 'employee']);
