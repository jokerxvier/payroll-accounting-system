<?php

declare(strict_types=1);

use App\Models\Pas\AuditLog;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Dashboard MVP — Inertia controller props contract.
 *
 * Helpers below are namespaced (`authDashboardAs`, `recordDashboardQueryCount`)
 * to avoid colliding with `authPerfAs()` / `recordQueryCount()` declared at
 * file scope in the performance suite. Pest collects every `*.php` under
 * `tests/Feature` and free functions are global, so duplicate names in
 * different files would crash the whole suite at boot.
 */

function authDashboardAs(string $role): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

function recordDashboardQueryCount(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('redirects guests to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

it('lets authenticated users visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

it('renders the dashboard component with all top-level prop keys', function () {
    $user = authDashboardAs('super-admin');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('kpis')
            ->has('actions')
            ->has('recentRuns')
            ->has('recentActivity')
            ->has('reminders')
        );
});

it('shapes the actions block per role', function () {
    $expectations = [
        'super-admin' => ['role' => 'super-admin', 'href' => route('admin.payroll-runs.create')],
        'payroll-officer' => ['role' => 'payroll-officer', 'href' => route('admin.payroll-runs.create')],
        'hr' => ['role' => 'hr', 'href' => route('employees.index')],
        'auditor' => ['role' => 'auditor', 'href' => route('admin.audit-logs.index')],
        'employee' => ['role' => 'employee', 'href' => route('dashboard')],
    ];

    foreach ($expectations as $role => $expected) {
        $user = authDashboardAs($role);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('actions.role', $expected['role'])
                ->where('actions.primaryCta.href', $expected['href'])
            );
    }
});

it('returns the latest payroll run and the five most-recent ones in descending order', function () {
    $user = authDashboardAs('super-admin');

    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();

    /** @var list<PayrollRun> $runs */
    $runs = [];
    for ($i = 0; $i < 6; $i++) {
        $runs[] = PayrollRun::factory()->create([
            'pay_period_id' => $period->id,
            'created_at' => now()->subMinutes(6 - $i),
        ]);
    }
    $newest = end($runs);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('kpis.latestRun.id', $newest->id)
            ->has('recentRuns', 5)
            ->where('recentRuns.0.id', $newest->id)
        );
});

it('returns nulls and empty lists when nothing has been generated yet', function () {
    $user = authDashboardAs('super-admin');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('kpis.latestRun', null)
            ->where('recentRuns', [])
            ->where('recentActivity', [])
            ->where('reminders', [])
        );
});

it('passes net totals as raw integer centavos', function () {
    $user = authDashboardAs('super-admin');

    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    PayrollRun::factory()->create([
        'pay_period_id' => $period->id,
        'total_employees' => 12,
        'total_net_pay_centavos' => 12_345_600,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('recentRuns.0.netTotalCentavos', 12_345_600)
            ->where('recentRuns.0.employeeCount', 12)
        );
});

it('filters recent activity by role', function () {
    $hr = authDashboardAs('hr');

    AuditLog::query()->create([
        'auditable_type' => PayrollRun::class,
        'auditable_id' => 99,
        'actor_id' => $hr->id,
        'action' => 'approved',
        'before' => null,
        'after' => ['x' => 1],
        'created_at' => now()->subMinutes(2),
    ]);
    AuditLog::query()->create([
        'auditable_type' => EmployeeProfile::class,
        'auditable_id' => 7,
        'actor_id' => $hr->id,
        'action' => 'updated',
        'before' => ['name' => 'old'],
        'after' => ['name' => 'new'],
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($hr)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('recentActivity', 1)
            ->where('recentActivity.0.targetLabel', 'EmployeeProfile #7')
        );
});

it('keeps the dashboard query budget under 15 queries with seeded data', function () {
    $user = authDashboardAs('super-admin');

    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    PayrollRun::factory()->count(6)->create(['pay_period_id' => $period->id]);

    EmployeeProfile::factory()->count(3)->create();

    for ($i = 0; $i < 8; $i++) {
        AuditLog::query()->create([
            'auditable_type' => PayrollRun::class,
            'auditable_id' => $i + 1,
            'actor_id' => $user->id,
            'action' => 'updated',
            'before' => ['x' => $i],
            'after' => ['x' => $i + 1],
            'created_at' => now()->subSeconds($i),
        ]);
    }

    $count = recordDashboardQueryCount(function () use ($user) {
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    });

    // Expected baseline (auth + role lookup + countActiveStaff +
    // countStaffJoinedThisMonth + latestOpen + latestNonVoided
    // + recentNonVoided + auditLogs + actor batch).
    expect($count)->toBeLessThan(15);
});
