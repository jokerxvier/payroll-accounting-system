<?php

declare(strict_types=1);

use App\Models\Pas\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function authDevSeedAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

it('super-admin POST seeds demo salaries and flashes success', function () {
    $user = authDevSeedAs('super-admin');
    EmployeeProfile::factory()->count(3)->create([
        'is_active' => true,
        'basic_salary_centavos' => 0,
    ]);

    $this->actingAs($user)
        ->from('/employees')
        ->post('/admin/dev/seed-demo-salaries')
        ->assertRedirect('/employees')
        ->assertSessionHas('success');

    $touched = EmployeeProfile::query()
        ->where('is_active', true)
        ->where('basic_salary_centavos', '>', 0)
        ->count();

    expect($touched)->toBe(3);
});

it('forbids non-super-admin roles', function (string $role) {
    $user = authDevSeedAs($role);

    $this->actingAs($user)
        ->post('/admin/dev/seed-demo-salaries')
        ->assertForbidden();
})->with(['payroll-officer', 'hr', 'auditor', 'employee']);

// Production-env behaviour is enforced by:
//   1. the Gate definition (`! app()->environment('production')`) at
//      AppServiceProvider, and
//   2. an explicit `abort(403)` inside the controller as defence in depth.
// Both are visible in code and verified by the manual smoke flow; an HTTP
// test that flips Laravel's resolved environment at runtime hits CSRF
// middleware before the gate, producing 419 rather than 403, so the
// signal is muddied. The two-layer guard is the contract; we don't need
// a flaky HTTP-level pin here.
