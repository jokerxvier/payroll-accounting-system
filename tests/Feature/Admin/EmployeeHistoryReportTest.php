<?php

declare(strict_types=1);

use App\Exports\EmployeeHistoryReportExport;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

function authHistoryAs(string $role): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('renders the employee-history page with no employee picked', function () {
    $user = authHistoryAs('super-admin');
    EmployeeProfile::factory()->count(2)->create();

    $this->actingAs($user)
        ->get('/admin/reports/employee-history')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/reports/employee-history')
                ->where('filters.employee', null)
                ->has('employees', 2)
                ->where('employee', null)
                ->has('rows', 0)
                ->where('totals.payslip_count', 0)
                ->has('ytd_by_year', 0),
        );
});

it('builds a per-employee timeline with cumulative totals', function () {
    $user = authHistoryAs('super-admin');
    $profile = EmployeeProfile::factory()->create(['lms_staff_id' => 4242]);

    $r1 = PayrollRun::factory()->computed()->create();
    $r2 = PayrollRun::factory()->computed()->create();

    Payslip::factory()->for($r1, 'payrollRun')->create([
        'lms_staff_id' => 4242,
        'employee_profile_id' => $profile->id,
        'gross_pay_centavos' => 4_500_000,
        'total_employee_deductions_centavos' => 500_000,
        'net_pay_centavos' => 4_000_000,
        'computed_at' => now()->subMonth(),
    ]);
    Payslip::factory()->for($r2, 'payrollRun')->create([
        'lms_staff_id' => 4242,
        'employee_profile_id' => $profile->id,
        'gross_pay_centavos' => 4_500_000,
        'total_employee_deductions_centavos' => 500_000,
        'net_pay_centavos' => 4_000_000,
        'computed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin/reports/employee-history?employee=4242')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->where('filters.employee', 4242)
                ->has('rows', 2)
                ->where('rows.0.gross_pay_centavos', 4_500_000)
                ->where('rows.0.cumulative_net_centavos', 4_000_000)
                ->where('rows.1.cumulative_net_centavos', 8_000_000)
                ->where('totals.payslip_count', 2)
                ->where('totals.gross_pay_centavos', 9_000_000)
                ->where('totals.total_net_pay_centavos', 8_000_000),
        );
});

it('excludes payslips from voided runs', function () {
    $user = authHistoryAs('super-admin');
    EmployeeProfile::factory()->create(['lms_staff_id' => 7]);

    $voided = PayrollRun::factory()->voided()->create();
    $computed = PayrollRun::factory()->computed()->create();

    Payslip::factory()->for($voided, 'payrollRun')->create([
        'lms_staff_id' => 7,
        'gross_pay_centavos' => 9_999_999,
        'net_pay_centavos' => 9_999_999,
    ]);
    Payslip::factory()->for($computed, 'payrollRun')->create([
        'lms_staff_id' => 7,
        'gross_pay_centavos' => 1_000_000,
        'net_pay_centavos' => 800_000,
    ]);

    $this->actingAs($user)
        ->get('/admin/reports/employee-history?employee=7')
        ->assertInertia(
            fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.gross_pay_centavos', 1_000_000)
                ->where('totals.gross_pay_centavos', 1_000_000),
        );
});

it('forbids the page for auditor + employee roles', function (string $role) {
    $user = authHistoryAs($role);
    $this->actingAs($user)
        ->get('/admin/reports/employee-history')
        ->assertForbidden();
})->with(['auditor', 'employee']);

it('downloads the Excel export when an employee is picked', function () {
    Excel::fake();
    $user = authHistoryAs('super-admin');
    EmployeeProfile::factory()->create(['lms_staff_id' => 99]);

    $this->actingAs($user)
        ->get('/admin/reports/employee-history/export?employee=99')
        ->assertOk();

    $expected = sprintf('employee-history_staff99_%s.xlsx', now()->toDateString());
    Excel::assertDownloaded(
        $expected,
        fn ($export): bool => $export instanceof EmployeeHistoryReportExport,
    );
});

it('rejects the Excel export when no employee is given', function () {
    $user = authHistoryAs('super-admin');

    $this->actingAs($user)
        ->get('/admin/reports/employee-history/export')
        ->assertStatus(422);
});

/*
 * Phase 4 W13 Stage C — YTD per-employee aggregates.
 */

it('groups payslips by calendar year for YTD totals', function () {
    $user = authHistoryAs('super-admin');
    EmployeeProfile::factory()->create(['lms_staff_id' => 5151]);

    $r1 = PayrollRun::factory()->computed()->create();
    $r2 = PayrollRun::factory()->computed()->create();
    $r3 = PayrollRun::factory()->computed()->create();

    Payslip::factory()->for($r1, 'payrollRun')->create([
        'lms_staff_id' => 5151,
        'gross_pay_centavos' => 1_000_000,
        'total_employee_deductions_centavos' => 100_000,
        'total_employer_contributions_centavos' => 50_000,
        'net_pay_centavos' => 900_000,
        'computed_at' => '2025-12-15 10:00:00',
    ]);
    Payslip::factory()->for($r2, 'payrollRun')->create([
        'lms_staff_id' => 5151,
        'gross_pay_centavos' => 2_000_000,
        'total_employee_deductions_centavos' => 200_000,
        'total_employer_contributions_centavos' => 100_000,
        'net_pay_centavos' => 1_800_000,
        'computed_at' => '2026-01-31 10:00:00',
    ]);
    Payslip::factory()->for($r3, 'payrollRun')->create([
        'lms_staff_id' => 5151,
        'gross_pay_centavos' => 3_000_000,
        'total_employee_deductions_centavos' => 300_000,
        'total_employer_contributions_centavos' => 150_000,
        'net_pay_centavos' => 2_700_000,
        'computed_at' => '2026-05-31 10:00:00',
    ]);

    $this->actingAs($user)
        ->get('/admin/reports/employee-history?employee=5151')
        ->assertInertia(
            fn ($page) => $page
                ->has('ytd_by_year', 2)
                // Most recent year first.
                ->where('ytd_by_year.0.year', 2026)
                ->where('ytd_by_year.0.payslip_count', 2)
                ->where('ytd_by_year.0.gross_pay_centavos', 5_000_000)
                ->where('ytd_by_year.0.total_employee_deductions_centavos', 500_000)
                ->where('ytd_by_year.0.total_employer_contributions_centavos', 250_000)
                ->where('ytd_by_year.0.total_net_pay_centavos', 4_500_000)
                ->where('ytd_by_year.1.year', 2025)
                ->where('ytd_by_year.1.payslip_count', 1)
                ->where('ytd_by_year.1.gross_pay_centavos', 1_000_000),
        );
});

it('excludes payslips from voided runs from YTD aggregates', function () {
    $user = authHistoryAs('super-admin');
    EmployeeProfile::factory()->create(['lms_staff_id' => 9090]);

    $voided = PayrollRun::factory()->voided()->create();
    $computed = PayrollRun::factory()->computed()->create();

    Payslip::factory()->for($voided, 'payrollRun')->create([
        'lms_staff_id' => 9090,
        'gross_pay_centavos' => 9_999_999,
        'net_pay_centavos' => 9_999_999,
        'computed_at' => '2026-05-15 10:00:00',
    ]);
    Payslip::factory()->for($computed, 'payrollRun')->create([
        'lms_staff_id' => 9090,
        'gross_pay_centavos' => 1_000_000,
        'net_pay_centavos' => 800_000,
        'computed_at' => '2026-05-20 10:00:00',
    ]);

    $this->actingAs($user)
        ->get('/admin/reports/employee-history?employee=9090')
        ->assertInertia(
            fn ($page) => $page
                ->has('ytd_by_year', 1)
                ->where('ytd_by_year.0.payslip_count', 1)
                ->where('ytd_by_year.0.gross_pay_centavos', 1_000_000)
                ->where('ytd_by_year.0.total_net_pay_centavos', 800_000),
        );
});
