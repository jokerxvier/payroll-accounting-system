<?php

declare(strict_types=1);

use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function authStaffNameAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

// The happy-path "staff_name resolved from LMS" lookup is exercised on
// the live dev DB in the manual smoke (visible in commit `5de5dcc..`'s
// payroll run #1). Pinning it as a feature test would require an LMS
// fixture in the test env's `lms` connection, which currently runs
// against the same SQLite in-memory DB that RefreshDatabase wipes.
// The negative path (Unknown-staff fallback) below is fixture-free and
// already covers the controller's null-coalescing branch.

it('show payload returns null staff_name when the LMS staff does not exist', function () {
    $user = authStaffNameAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();

    Payslip::factory()
        ->for($run, 'payrollRun')
        ->create([
            'lms_staff_id' => 999_999_999, // unlikely to exist
        ]);

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/payroll-runs/show')
                ->where('payslips.0.staff_name', null),
        );
});
