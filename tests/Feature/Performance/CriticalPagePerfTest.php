<?php

declare(strict_types=1);

use App\Models\Pas\AuditLog;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Phase 4 W15 — performance regression gates for the highest-traffic
 * authenticated pages. Each test seeds a representative dataset and
 * pins the query count via DB::enableQueryLog. The thresholds are
 * deliberately a hair higher than the observed baseline so the gate
 * fires on a regression (e.g., a relationship eagerly removed) but
 * doesn't fail on cosmetic add-an-eager-load drift.
 *
 * Read the failing test's actual count vs. the threshold; if a fix
 * involved adding ONE legitimate eager-load, bump the threshold by ONE.
 * Don't bump by N to silence an N+1.
 */

function authPerfAs(string $role): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

function recordQueryCount(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('pins the query count for the payroll-runs index with 20 runs', function () {
    $user = authPerfAs('super-admin');

    // Seed 20 runs across 4 periods so the index has a realistic shape.
    $periods = collect(range(1, 4))->map(
        fn (int $i): PayPeriod => PayPeriod::factory()->monthly(2026, $i)->open()->create(),
    );
    foreach ($periods as $period) {
        PayrollRun::factory()->count(5)->create(['pay_period_id' => $period->id]);
    }

    $count = recordQueryCount(function () use ($user) {
        $this->actingAs($user)->get('/admin/payroll-runs')->assertOk();
    });

    // Expected: ~6–8 queries (auth, role lookup, runs list, eager-loaded
    // payPeriod + 4 actor relationships). Threshold sits well below
    // a 20-row N+1 (which would be 80+).
    expect($count)->toBeLessThan(15);
});

it('pins the query count for the payroll-run show with 25 payslips', function () {
    $user = authPerfAs('super-admin');
    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    $run = PayrollRun::factory()->computed()->create(['pay_period_id' => $period->id]);
    Payslip::factory()->count(25)->for($run, 'payrollRun')->create();

    $count = recordQueryCount(function () use ($user, $run) {
        $this->actingAs($user)->get('/admin/payroll-runs/'.$run->id)->assertOk();
    });

    // Expected: ~5–8 queries (auth, role, run + relations, payslips
    // eager-loaded, single LMS Staff batch lookup, can-* gates).
    expect($count)->toBeLessThan(15);
});

it('pins the query count for the employees index page', function () {
    $user = authPerfAs('super-admin');
    EmployeeProfile::factory()->count(15)->create();

    $count = recordQueryCount(function () use ($user) {
        $this->actingAs($user)->get('/employees')->assertOk();
    });

    // The directory uses an LMS-staff repository read; pin generously
    // because the LMS join produces several auxiliary queries that are
    // legitimate (department + designation lookups + pagination count).
    expect($count)->toBeLessThan(20);
});

it('pins the query count for the audit-log index with 30 entries', function () {
    $user = authPerfAs('super-admin');
    for ($i = 0; $i < 30; $i++) {
        AuditLog::query()->create([
            'auditable_type' => EmployeeProfile::class,
            'auditable_id' => $i + 1,
            'actor_id' => $user->id,
            'action' => 'updated',
            'before' => ['x' => $i],
            'after' => ['x' => $i + 1],
            'created_at' => now()->subSeconds($i),
        ]);
    }

    $count = recordQueryCount(function () use ($user) {
        $this->actingAs($user)->get('/admin/audit-logs')->assertOk();
    });

    // 25-per-page paginate + a single batched actor-name lookup +
    // distinct() probes for the dropdowns. N+1 on actor names would
    // push this to 25+ extra queries.
    expect($count)->toBeLessThan(15);
});

it('pins the query count for the payroll summary report', function () {
    $user = authPerfAs('super-admin');
    $periods = collect(range(1, 3))->map(
        fn (int $m): PayPeriod => PayPeriod::factory()->monthly(2026, $m)->open()->create(),
    );
    foreach ($periods as $period) {
        $run = PayrollRun::factory()->computed()->create(['pay_period_id' => $period->id]);
        Payslip::factory()->count(5)->for($run, 'payrollRun')->create();
    }

    $count = recordQueryCount(function () use ($user) {
        $this->actingAs($user)
            ->get('/admin/reports/payroll-summary?from=2026-01-01&to=2026-12-31')
            ->assertOk();
    });

    // The summary aggregates payslips per run via two SUM/COUNT queries
    // each. With 3 runs, that's 6 aggregation queries + auth + period
    // lookup + run list = ~12 baseline. Keep the threshold below an
    // N+1 explosion (which would scale with runs and grow much faster).
    expect($count)->toBeLessThan(20);
});
