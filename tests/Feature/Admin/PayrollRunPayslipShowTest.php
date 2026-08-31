<?php

declare(strict_types=1);

use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function authPayslipShowAs(string $role): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('renders the standalone payslip page for super-admin', function () {
    $user = authPayslipShowAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();
    $payslip = Payslip::factory()->for($run, 'payrollRun')->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/payslips/'.$payslip->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/payroll-runs/payslips/show')
                ->where('run.id', $run->id)
                ->where('payslip.id', $payslip->id)
                ->has('payslip.audit_lines')
                ->has('employee'),
        );
});

it('hands the screen the same figures the PDF renders from', function () {
    // The screen and the PDF are one document in two media. The page used to
    // receive only run/payslip/employee and split `audit_lines` itself, which
    // is how the two drifted into showing different things.
    $user = authPayslipShowAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();
    $payslip = Payslip::factory()->for($run, 'payrollRun')->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/payslips/'.$payslip->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('earnings', 1)
                ->has('deductions', 1)
                ->has('employerLines')
                ->has('contributions')
                ->has('school')
                // Labels arrive humanised, so the screen cannot print
                // "(employee)" where the printout does not.
                ->where('deductions.0.label', 'SSS contribution')
                // A URL for the browser, never the PDF's base64 data URI:
                // that would put ~300 KB of image in every page payload.
                ->where('school.logo_url', fn ($url) => $url === null || ! str_starts_with((string) $url, 'data:')),
        );
});

it('sends a contribution ledger the screen does not have to compute', function () {
    $user = authPayslipShowAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();
    $payslip = Payslip::factory()->for($run, 'payrollRun')->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/payslips/'.$payslip->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('contributions', 1)
                ->where('contributions.0.label', 'SSS')
                ->where('contributions.0.yours', 90_000)
                ->where('contributions.0.school', 0)
                ->where('contributions.0.credited', 90_000),
        );
});

it('returns 404 when the payslip belongs to a different run', function () {
    $user = authPayslipShowAs('super-admin');
    $runA = PayrollRun::factory()->computed()->create();
    $runB = PayrollRun::factory()->computed()->create();
    $payslip = Payslip::factory()->for($runA, 'payrollRun')->create();

    // Hit run B's URL with run A's payslip id — should NOT leak through.
    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$runB->id.'/payslips/'.$payslip->id)
        ->assertNotFound();
});

it('allows payroll-officer and hr to view a payslip (maker roles)', function (string $role) {
    $user = authPayslipShowAs($role);
    $run = PayrollRun::factory()->computed()->create();
    $payslip = Payslip::factory()->for($run, 'payrollRun')->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/payslips/'.$payslip->id)
        ->assertOk();
})->with(['payroll-officer', 'hr']);

it('forbids the payslip page for auditor and employee', function (string $role) {
    $user = authPayslipShowAs($role);
    $run = PayrollRun::factory()->computed()->create();
    $payslip = Payslip::factory()->for($run, 'payrollRun')->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/payslips/'.$payslip->id)
        ->assertForbidden();
})->with(['auditor', 'employee']);

it('streams a PDF for super-admin', function () {
    $user = authPayslipShowAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();
    $payslip = Payslip::factory()->for($run, 'payrollRun')->create();

    $response = $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/payslips/'.$payslip->id.'/pdf');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toContain('application/pdf');
    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('.pdf');

    // Magic-byte check: dompdf output begins with %PDF-.
    expect(substr($response->getContent() ?: '', 0, 5))->toBe('%PDF-');
});

it('returns 404 on the PDF route when the payslip belongs to a different run', function () {
    $user = authPayslipShowAs('super-admin');
    $runA = PayrollRun::factory()->computed()->create();
    $runB = PayrollRun::factory()->computed()->create();
    $payslip = Payslip::factory()->for($runA, 'payrollRun')->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$runB->id.'/payslips/'.$payslip->id.'/pdf')
        ->assertNotFound();
});

it('allows payroll-officer and hr to download the PDF (maker roles)', function (string $role) {
    $user = authPayslipShowAs($role);
    $run = PayrollRun::factory()->computed()->create();
    $payslip = Payslip::factory()->for($run, 'payrollRun')->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/payslips/'.$payslip->id.'/pdf')
        ->assertOk();
})->with(['payroll-officer', 'hr']);

it('forbids the PDF route for auditor and employee', function (string $role) {
    $user = authPayslipShowAs($role);
    $run = PayrollRun::factory()->computed()->create();
    $payslip = Payslip::factory()->for($run, 'payrollRun')->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/payslips/'.$payslip->id.'/pdf')
        ->assertForbidden();
})->with(['auditor', 'employee']);
