<?php

declare(strict_types=1);

use App\Models\Pas\PayPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function authPayPeriodsAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

it('renders the index for super-admin', function () {
    $user = authPayPeriodsAs('super-admin');
    PayPeriod::factory()->monthly(2026, 5)->open()->create();

    $this->actingAs($user)
        ->get('/admin/pay-periods')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/pay-periods/index')
                ->where('can.create', true)
                ->has('periods', 1),
        );
});

it('forbids index for non-super-admin roles', function (string $role) {
    $user = authPayPeriodsAs($role);

    $this->actingAs($user)
        ->get('/admin/pay-periods')
        ->assertForbidden();
})->with(['payroll-officer', 'hr', 'auditor', 'employee']);

it('renders the create form', function () {
    $user = authPayPeriodsAs('super-admin');

    $this->actingAs($user)
        ->get('/admin/pay-periods/create')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->component('admin/pay-periods/create'),
        );
});

it('persists a valid period and redirects to the index', function () {
    $user = authPayPeriodsAs('super-admin');

    $this->actingAs($user)
        ->from('/admin/pay-periods/create')
        ->post('/admin/pay-periods', [
            'code' => '2026-05',
            'frequency' => 'monthly',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'cutoff_date' => null,
            'status' => 'open',
        ])
        ->assertRedirect('/admin/pay-periods')
        ->assertSessionHas('success');

    $period = PayPeriod::query()->where('code', '2026-05')->firstOrFail();
    expect($period->frequency)->toBe('monthly')
        ->and($period->status)->toBe('open')
        ->and($period->start_date->toDateString())->toBe('2026-05-01')
        ->and($period->end_date->toDateString())->toBe('2026-05-31');
});

it('rejects a duplicate code', function () {
    $user = authPayPeriodsAs('super-admin');
    PayPeriod::factory()->monthly(2026, 5)->create();

    $this->actingAs($user)
        ->from('/admin/pay-periods/create')
        ->post('/admin/pay-periods', [
            'code' => '2026-05',
            'frequency' => 'monthly',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'status' => 'open',
        ])
        ->assertSessionHasErrors('code');
});

it('rejects when end_date is before start_date', function () {
    $user = authPayPeriodsAs('super-admin');

    $this->actingAs($user)
        ->from('/admin/pay-periods/create')
        ->post('/admin/pay-periods', [
            'code' => '2026-05-BAD',
            'frequency' => 'monthly',
            'start_date' => '2026-05-31',
            'end_date' => '2026-05-01',
            'status' => 'open',
        ])
        ->assertSessionHasErrors('end_date');
});

it('forbids store for non-super-admin roles', function (string $role) {
    $user = authPayPeriodsAs($role);

    $this->actingAs($user)
        ->post('/admin/pay-periods', [
            'code' => '2026-05',
            'frequency' => 'monthly',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'status' => 'open',
        ])
        ->assertForbidden();
})->with(['payroll-officer', 'hr', 'auditor', 'employee']);
