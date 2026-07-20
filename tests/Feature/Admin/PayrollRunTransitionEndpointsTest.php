<?php

declare(strict_types=1);

use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function authTransitionsAs(string $role): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('super-admin can submit a computed run for approval', function () {
    $user = authTransitionsAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/submit')
        ->assertRedirect('/admin/payroll-runs/'.$run->id)
        ->assertSessionHas('success');

    expect($run->fresh()->status)->toBe(PayrollRun::STATUS_PENDING_APPROVAL);
});

it('rejects submit on a draft run', function () {
    $user = authTransitionsAs('super-admin');
    $run = PayrollRun::factory()->create(['status' => PayrollRun::STATUS_DRAFT]);

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/submit')
        ->assertForbidden();
});

it('super-admin can approve a pending_approval run', function () {
    $user = authTransitionsAs('super-admin');
    $run = PayrollRun::factory()->create(['status' => PayrollRun::STATUS_PENDING_APPROVAL]);

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/approve')
        ->assertRedirect('/admin/payroll-runs/'.$run->id);

    expect($run->fresh()->status)->toBe(PayrollRun::STATUS_APPROVED);
});

it('super-admin can post an approved run', function () {
    $user = authTransitionsAs('super-admin');
    $run = PayrollRun::factory()->approved()->create();

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/post')
        ->assertRedirect('/admin/payroll-runs/'.$run->id);

    expect($run->fresh()->status)->toBe(PayrollRun::STATUS_POSTED);
});

it('super-admin can post a computed run directly when approval is bypassed (DEMO)', function () {
    $user = authTransitionsAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/post')
        ->assertRedirect('/admin/payroll-runs/'.$run->id);

    expect($run->fresh()->status)->toBe(PayrollRun::STATUS_POSTED);
});

it('void on a posted run is forbidden — posted is final', function () {
    $user = authTransitionsAs('super-admin');
    $run = PayrollRun::factory()->create(['status' => PayrollRun::STATUS_POSTED]);

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/void')
        ->assertForbidden();
});

it('void on an approved run moves to voided', function () {
    $user = authTransitionsAs('super-admin');
    $run = PayrollRun::factory()->approved()->create();

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/void')
        ->assertRedirect('/admin/payroll-runs/'.$run->id);

    expect($run->fresh()->status)->toBe(PayrollRun::STATUS_VOIDED);
});

it('approver-only transitions are forbidden for makers', function (string $role, string $endpoint) {
    $user = authTransitionsAs($role);
    $run = PayrollRun::factory()->computed()->create();

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/'.$endpoint)
        ->assertForbidden();
})->with([
    ['payroll-officer', 'approve'],
    ['payroll-officer', 'post'],
    ['payroll-officer', 'void'],
    ['hr', 'approve'],
    ['hr', 'post'],
    ['hr', 'void'],
]);

it('all transitions are forbidden for auditor and employee', function (string $role, string $endpoint) {
    $user = authTransitionsAs($role);
    $run = PayrollRun::factory()->computed()->create();

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/'.$endpoint)
        ->assertForbidden();
})->with([
    ['auditor', 'submit'],
    ['auditor', 'approve'],
    ['auditor', 'post'],
    ['auditor', 'void'],
    ['employee', 'submit'],
    ['employee', 'approve'],
    ['employee', 'post'],
    ['employee', 'void'],
]);

it('show payload exposes can flags reflecting current status', function () {
    $user = authTransitionsAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/payroll-runs/show')
                ->where('can.submit', true)
                ->where('can.approve', false)
                // DEMO: computed is directly postable now.
                ->where('can.post', true)
                ->where('can.void', true)
                ->where('can.delete', true),
        );
});

it('super-admin can delete a run and its payslips cascade', function () {
    $user = authTransitionsAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();
    Payslip::factory()->count(2)->for($run, 'payrollRun')->create();

    $this->actingAs($user)
        ->delete('/admin/payroll-runs/'.$run->id)
        ->assertRedirect('/admin/payroll-runs')
        ->assertSessionHas('success');

    expect(PayrollRun::query()->whereKey($run->id)->exists())->toBeFalse()
        ->and(Payslip::query()->where('payroll_run_id', $run->id)->exists())->toBeFalse();
});

it('delete is forbidden for auditor and employee', function (string $role) {
    $user = authTransitionsAs($role);
    $run = PayrollRun::factory()->computed()->create();

    $this->actingAs($user)
        ->delete('/admin/payroll-runs/'.$run->id)
        ->assertForbidden();

    expect(PayrollRun::query()->whereKey($run->id)->exists())->toBeTrue();
})->with(['auditor', 'employee']);
