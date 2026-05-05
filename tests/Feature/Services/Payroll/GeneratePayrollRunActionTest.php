<?php

declare(strict_types=1);

use App\Actions\Payroll\GeneratePayrollRunAction;
use App\Jobs\Payroll\ComputeEmployeePayslipJob;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\Pas\StatutoryContribution;
use App\Services\Payroll\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * Pin the orchestration contract for "Generate payroll":
 *   - one PayrollRun row per execution
 *   - one ComputeEmployeePayslipJob per active employee
 *   - run starts in `computing`, ends in `computed` after the batch finalizer
 *   - the run's totals are rolled up from payslips, not computed by the action
 *   - exempted employees carry the frozen exemption list on the Payslip
 *   - inactive employees are skipped (no payslip emitted)
 */

function seedFourPhContributions(): void
{
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->philhealth()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->pagibig()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);
}

it('creates a PayrollRun and dispatches one job per active employee', function () {
    seedFourPhContributions();
    Bus::fake();

    EmployeeProfile::factory()->count(3)->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000,
    ]);
    // One inactive employee — must be excluded from the dispatch.
    EmployeeProfile::factory()->create([
        'is_active' => false,
        'basic_salary_centavos' => 4_500_000,
    ]);

    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();

    $run = app(GeneratePayrollRunAction::class)->execute($period);

    expect($run->status)->toBe(PayrollRun::STATUS_COMPUTING)
        ->and($run->total_employees)->toBe(3)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->computed_at)->toBeNull();

    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 3
            && $batch->jobs->every(fn ($job): bool => $job instanceof ComputeEmployeePayslipJob);
    });
});

it('finalizes a run to `computed` with rolled-up totals after every job lands', function () {
    seedFourPhContributions();
    Queue::fake();
    // Drive jobs synchronously after the action returns so the batch finalizer
    // path is exercised explicitly via the public ::finalize() helper.

    $profiles = EmployeeProfile::factory()->count(3)->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
    ]);

    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();

    $run = app(GeneratePayrollRunAction::class)->execute($period);

    // Run the per-employee jobs synchronously (Queue::fake captures them but
    // doesn't execute; we hand-execute to verify Payslip persistence).
    foreach ($profiles as $profile) {
        (new ComputeEmployeePayslipJob(
            payrollRunId: $run->id,
            employeeProfileId: $profile->id,
        ))->handle(app(PayrollComputationService::class));
    }

    GeneratePayrollRunAction::finalize($run->fresh());

    $run->refresh();

    expect($run->status)->toBe(PayrollRun::STATUS_COMPUTED)
        ->and($run->total_employees)->toBe(3)
        ->and($run->computed_at)->not->toBeNull()
        ->and(Payslip::query()->where('payroll_run_id', $run->id)->count())->toBe(3);

    // Each payslip should have the canonical PH numbers since basic_salary
    // and seeded contributions match the engine's canonical fixture.
    $payslips = Payslip::query()->where('payroll_run_id', $run->id)->get();
    foreach ($payslips as $payslip) {
        expect($payslip->net_pay_centavos)->toBe(4_500_000 - (90_000 + 112_500 + 10_000 + 378_340))
            ->and($payslip->applied_exemptions)->toBe([]);
    }

    // Totals roll up from per-payslip aggregation.
    $expectedTotalEe = 3 * (90_000 + 112_500 + 10_000 + 378_340);
    expect($run->total_employee_deductions_centavos)->toBe($expectedTotalEe)
        ->and($run->total_net_pay_centavos)->toBe(3 * (4_500_000 - (90_000 + 112_500 + 10_000 + 378_340)));
});

it('freezes the per-employee exemption list onto the payslip', function () {
    seedFourPhContributions();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
        'date_terminated' => null,
        'exempted_contribution_codes' => ['SSS', 'PHILHEALTH'],
    ]);

    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();

    $run = app(GeneratePayrollRunAction::class)->execute($period);

    // Run the single per-employee job synchronously.
    (new ComputeEmployeePayslipJob(
        payrollRunId: $run->id,
        employeeProfileId: $profile->id,
    ))->handle(app(PayrollComputationService::class));

    $payslip = Payslip::query()
        ->where('payroll_run_id', $run->id)
        ->where('employee_profile_id', $profile->id)
        ->firstOrFail();

    expect($payslip->applied_exemptions)->toBe(['SSS', 'PHILHEALTH']);

    // Engine path skipped SSS + PhilHealth lines, so audit_lines should not
    // contain SSS_EMPLOYEE / PHILHEALTH_EMPLOYEE entries.
    $codes = array_map(fn (array $line): string => $line['code'], $payslip->audit_lines);
    expect($codes)->not->toContain('SSS_EMPLOYEE')
        ->and($codes)->not->toContain('PHILHEALTH_EMPLOYEE')
        ->and($codes)->toContain('PAGIBIG_EMPLOYEE')
        ->and($codes)->toContain('BIR_WITHHOLDING_EMPLOYEE');
});

it('creates an immediately-computed run when there are no active employees', function () {
    seedFourPhContributions();
    Bus::fake();

    EmployeeProfile::factory()->create([
        'is_active' => false,
        'basic_salary_centavos' => 4_500_000,
    ]);

    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();

    $run = app(GeneratePayrollRunAction::class)->execute($period);

    expect($run->status)->toBe(PayrollRun::STATUS_COMPUTED)
        ->and($run->total_employees)->toBe(0)
        ->and($run->computed_at)->not->toBeNull();

    Bus::assertNothingBatched();
});

it('persists payslips idempotently on a job retry (unique constraint)', function () {
    seedFourPhContributions();

    $profile = EmployeeProfile::factory()->create([
        'is_active' => true,
        'basic_salary_centavos' => 4_500_000,
        'date_hired' => '2024-01-01',
    ]);
    $period = PayPeriod::factory()->monthly(2026, 5)->open()->create();
    $run = app(GeneratePayrollRunAction::class)->execute($period);

    $engine = app(PayrollComputationService::class);

    // First run — inserts a payslip.
    (new ComputeEmployeePayslipJob(
        payrollRunId: $run->id,
        employeeProfileId: $profile->id,
    ))->handle($engine);

    // Retry — must update-or-create the same row, never duplicate.
    (new ComputeEmployeePayslipJob(
        payrollRunId: $run->id,
        employeeProfileId: $profile->id,
    ))->handle($engine);

    expect(Payslip::query()->where('payroll_run_id', $run->id)->count())->toBe(1);
});
