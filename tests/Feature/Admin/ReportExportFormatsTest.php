<?php

declare(strict_types=1);

use App\Exports\EmployeeHistoryReportExport;
use App\Exports\PayrollSummaryReportExport;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;

/*
 * Phase 4 acceptance criterion: "Three reports export cleanly to all three
 * formats." W13 shipped xlsx only; these pin xlsx / csv / pdf across both
 * report endpoints.
 *
 * The format is a `format` query parameter defaulting to xlsx, so the
 * original export links keep working untouched.
 */

function reportExportAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function seedRunForExport(): PayrollRun
{
    $period = PayPeriod::factory()->create([
        'code' => '2026-05',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
    ]);

    $run = PayrollRun::factory()->create([
        'pay_period_id' => $period->id,
        'status' => PayrollRun::STATUS_POSTED,
        'total_employee_deductions_centavos' => 250_000,
        'total_employer_contributions_centavos' => 180_000,
        'total_net_pay_centavos' => 4_750_000,
    ]);

    Payslip::factory()->create([
        'payroll_run_id' => $run->id,
        'lms_staff_id' => 4242,
        'gross_pay_centavos' => 5_000_000,
        'total_employee_deductions_centavos' => 250_000,
        'total_employer_contributions_centavos' => 180_000,
        'net_pay_centavos' => 4_750_000,
    ]);

    return $run;
}

it('defaults the payroll summary export to xlsx when no format is given', function () {
    Excel::fake();
    seedRunForExport();

    $this->actingAs(reportExportAuthAs('payroll-officer'))
        ->get('/admin/reports/payroll-summary/export?from=2026-05-01&to=2026-05-31')
        ->assertOk();

    Excel::assertDownloaded(
        'payroll-summary_2026-05-01_2026-05-31.xlsx',
        fn ($export): bool => $export instanceof PayrollSummaryReportExport,
    );
});

it('exports the payroll summary as csv', function () {
    Excel::fake();
    seedRunForExport();

    $this->actingAs(reportExportAuthAs('payroll-officer'))
        ->get('/admin/reports/payroll-summary/export?from=2026-05-01&to=2026-05-31&format=csv')
        ->assertOk();

    Excel::assertDownloaded(
        'payroll-summary_2026-05-01_2026-05-31.csv',
        fn ($export): bool => $export instanceof PayrollSummaryReportExport,
    );
});

it('exports the payroll summary as a pdf', function () {
    seedRunForExport();

    $response = $this->actingAs(reportExportAuthAs('payroll-officer'))
        ->get('/admin/reports/payroll-summary/export?from=2026-05-01&to=2026-05-31&format=pdf')
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect($response->headers->get('content-disposition'))
        ->toContain('payroll-summary_2026-05-01_2026-05-31.pdf');

    // A real dompdf document, not an empty body or an error page.
    // dompdf's ->download() returns a plain Response, not a streamed one.
    $body = $response->getContent();
    expect(substr($body, 0, 4))->toBe('%PDF');
    expect(strlen($body))->toBeGreaterThan(1000);
});

it('renders a payroll summary pdf even when the range has no runs', function () {
    $response = $this->actingAs(reportExportAuthAs('payroll-officer'))
        ->get('/admin/reports/payroll-summary/export?from=2030-01-01&to=2030-01-31&format=pdf')
        ->assertOk();

    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});

it('defaults the employee history export to xlsx', function () {
    Excel::fake();
    seedRunForExport();

    $this->actingAs(reportExportAuthAs('payroll-officer'))
        ->get('/admin/reports/employee-history/export?employee=4242')
        ->assertOk();

    Excel::assertDownloaded(
        sprintf('employee-history_staff4242_%s.xlsx', now()->toDateString()),
        fn ($export): bool => $export instanceof EmployeeHistoryReportExport,
    );
});

it('exports the employee history as csv', function () {
    Excel::fake();
    seedRunForExport();

    $this->actingAs(reportExportAuthAs('payroll-officer'))
        ->get('/admin/reports/employee-history/export?employee=4242&format=csv')
        ->assertOk();

    Excel::assertDownloaded(
        sprintf('employee-history_staff4242_%s.csv', now()->toDateString()),
        fn ($export): bool => $export instanceof EmployeeHistoryReportExport,
    );
});

it('exports the employee history as a pdf', function () {
    seedRunForExport();

    $response = $this->actingAs(reportExportAuthAs('payroll-officer'))
        ->get('/admin/reports/employee-history/export?employee=4242&format=pdf')
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect($response->headers->get('content-disposition'))
        ->toContain(sprintf('employee-history_staff4242_%s.pdf', now()->toDateString()));

    $body = $response->getContent();
    expect(substr($body, 0, 4))->toBe('%PDF');
    expect(strlen($body))->toBeGreaterThan(1000);
});

it('still requires an employee for the history export in every format', function (string $format) {
    $this->actingAs(reportExportAuthAs('payroll-officer'))
        ->get("/admin/reports/employee-history/export?format={$format}")
        ->assertStatus(422);
})->with(['xlsx', 'csv', 'pdf']);

it('rejects an unrecognised format rather than silently falling back', function (string $endpoint) {
    // Falling back to xlsx on a typo would hand the operator a file type
    // they did not ask for, with no signal that anything went wrong.
    $this->actingAs(reportExportAuthAs('payroll-officer'))
        ->get("{$endpoint}?employee=4242&format=docx")
        ->assertStatus(422);
})->with([
    '/admin/reports/payroll-summary/export',
    '/admin/reports/employee-history/export',
]);

it('keeps every export format behind the report roles', function (string $format) {
    $this->actingAs(reportExportAuthAs('employee'))
        ->get("/admin/reports/payroll-summary/export?format={$format}")
        ->assertForbidden();

    $this->actingAs(reportExportAuthAs('employee'))
        ->get("/admin/reports/employee-history/export?employee=4242&format={$format}")
        ->assertForbidden();
})->with(['xlsx', 'csv', 'pdf']);
