<?php

declare(strict_types=1);

use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use Database\Seeders\DemoPayrollSeeder;

/*
 * DemoPayrollSeeder coverage.
 *
 * Pins the demo-payroll contract: catalog + users + profiles + periods +
 * runs + payslips all land after one `db:seed --class=DemoPayrollSeeder`.
 * Re-running keeps row counts stable. Each run reaches its declared
 * lifecycle status, and every payslip carries real engine output (gross
 * > 0, net > 0, gross >= net, audit_lines populated).
 *
 * Test environment: default connection is sqlite (via phpunit.xml), the
 * `lms` connection points at the live MySQL `payroll_db` — same setup
 * EmployeeIndexTest etc. rely on. The seeder reads from `lms` for staff
 * but writes only to `pas_*` tables on the default connection.
 */

it('runs without error against a fresh database', function (): void {
    expect(fn () => $this->seed(DemoPayrollSeeder::class))->not->toThrow(Throwable::class);
});

it('creates at least three demo employee profiles linked to LMS staff', function (): void {
    $this->seed(DemoPayrollSeeder::class);

    expect(EmployeeProfile::query()->count())->toBeGreaterThanOrEqual(3);

    // Every demo profile carries a real LMS staff id and a non-zero salary.
    EmployeeProfile::query()->get()->each(function (EmployeeProfile $profile): void {
        expect($profile->lms_staff_id)->toBeGreaterThan(0);
        expect($profile->basic_salary_centavos)->toBeGreaterThan(0);
        expect($profile->is_active)->toBeTrue();
    });
});

it('creates the four DEMO- pay periods', function (): void {
    $this->seed(DemoPayrollSeeder::class);

    foreach (
        [
            'DEMO-2026-02-MONTH',
            'DEMO-2026-03-MONTH',
            'DEMO-2026-04-S1',
            'DEMO-2026-05-MONTH',
        ] as $code
    ) {
        expect(PayPeriod::query()->where('code', $code)->exists())
            ->toBeTrue("expected demo pay period {$code}");
    }
});

it('creates at least three payroll runs spanning the lifecycle states', function (): void {
    $this->seed(DemoPayrollSeeder::class);

    $demoPeriodIds = PayPeriod::query()->where('code', 'like', 'DEMO-%')->pluck('id');

    $runs = PayrollRun::query()->whereIn('pay_period_id', $demoPeriodIds)->get();

    expect($runs->count())->toBeGreaterThanOrEqual(3);

    // Each demo period carries exactly one (non-voided) run.
    foreach ($demoPeriodIds as $periodId) {
        expect(PayrollRun::query()->where('pay_period_id', $periodId)->whereNull('voided_at')->count())
            ->toBe(1);
    }

    // Status variety: at least three distinct statuses across the demo runs.
    $statuses = $runs->pluck('status')->unique()->values()->all();
    expect(count($statuses))->toBeGreaterThanOrEqual(3, 'expected lifecycle variety across demo runs');
});

it('each computed/approved/posted run has a payslip per active employee', function (): void {
    $this->seed(DemoPayrollSeeder::class);

    $demoPeriodIds = PayPeriod::query()->where('code', 'like', 'DEMO-%')->pluck('id');

    $activeEmployeeCount = EmployeeProfile::query()->where('is_active', true)->count();
    expect($activeEmployeeCount)->toBeGreaterThanOrEqual(3);

    $computedOrLater = PayrollRun::query()
        ->whereIn('pay_period_id', $demoPeriodIds)
        ->whereIn('status', [
            PayrollRun::STATUS_COMPUTED,
            PayrollRun::STATUS_PENDING_APPROVAL,
            PayrollRun::STATUS_APPROVED,
            PayrollRun::STATUS_POSTED,
        ])
        ->get();

    expect($computedOrLater->count())->toBeGreaterThanOrEqual(3);

    foreach ($computedOrLater as $run) {
        expect(Payslip::query()->where('payroll_run_id', $run->id)->count())
            ->toBe($activeEmployeeCount, "run #{$run->id} should have one payslip per employee");
    }
});

it('every payslip has populated totals and a non-empty audit line list', function (): void {
    $this->seed(DemoPayrollSeeder::class);

    $payslips = Payslip::query()->get();

    expect($payslips->count())->toBeGreaterThan(0);

    foreach ($payslips as $payslip) {
        expect($payslip->gross_pay_centavos)->toBeGreaterThan(0, "payslip #{$payslip->id} gross > 0");
        expect($payslip->net_pay_centavos)->toBeGreaterThan(0, "payslip #{$payslip->id} net > 0");
        // Deductions reduce gross — net cannot exceed gross on demo data.
        expect($payslip->net_pay_centavos)->toBeLessThanOrEqual(
            $payslip->gross_pay_centavos,
            "payslip #{$payslip->id}: net must be ≤ gross",
        );

        // The engine emits at least one audit line per payslip (basic pay).
        expect($payslip->audit_lines)->toBeArray();
        expect(count($payslip->audit_lines))->toBeGreaterThanOrEqual(1);

        // Every audit line carries the runtime PayrollLineItem shape.
        foreach ($payslip->audit_lines as $line) {
            expect($line)->toHaveKeys(['code', 'label', 'amount', 'bucket']);
        }
    }
});

it('is idempotent — re-running the seeder does not duplicate periods, runs, or payslips', function (): void {
    $this->seed(DemoPayrollSeeder::class);

    $periodsAfterFirst = PayPeriod::query()->count();
    $runsAfterFirst = PayrollRun::query()->count();
    $payslipsAfterFirst = Payslip::query()->count();
    $profilesAfterFirst = EmployeeProfile::query()->count();

    $this->seed(DemoPayrollSeeder::class);

    expect(PayPeriod::query()->count())->toBe($periodsAfterFirst);
    expect(PayrollRun::query()->count())->toBe($runsAfterFirst);
    expect(Payslip::query()->count())->toBe($payslipsAfterFirst);
    expect(EmployeeProfile::query()->count())->toBe($profilesAfterFirst);
});

it('depends on DemoCatalogSeeder so allowances and deduction types are present', function (): void {
    $this->seed(DemoPayrollSeeder::class);

    // DemoCatalogSeeder seeds `transportation`, `rice_subsidy`, and `hmo`.
    // The seeder uses these to wire subscriptions, so their absence would
    // be a regression. Re-asserting here keeps the dependency explicit.
    expect(Allowance::query()->where('code', 'transportation')->exists())->toBeTrue();
    expect(Allowance::query()->where('code', 'rice_subsidy')->exists())->toBeTrue();
    expect(DeductionType::query()->where('code', 'hmo')->exists())->toBeTrue();
});

it('each demo profile carries the seeded allowance and deduction subscriptions', function (): void {
    $this->seed(DemoPayrollSeeder::class);

    $profiles = EmployeeProfile::query()->get();
    expect($profiles->count())->toBeGreaterThan(0);

    foreach ($profiles as $profile) {
        // 2 allowances (Transportation + Rice Subsidy) on each profile.
        expect(EmployeeAllowance::query()->where('employee_profile_id', $profile->id)->count())
            ->toBeGreaterThanOrEqual(2, "profile #{$profile->id} should have ≥ 2 allowance subscriptions");
        // 1 deduction (HMO).
        expect(EmployeeDeduction::query()->where('employee_profile_id', $profile->id)->count())
            ->toBeGreaterThanOrEqual(1, "profile #{$profile->id} should have ≥ 1 deduction subscription");
    }
});
