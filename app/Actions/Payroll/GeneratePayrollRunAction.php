<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Jobs\Payroll\ComputeEmployeePayslipJob;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates the "Generate payroll" lifecycle for one pay period.
 *
 * Lifecycle implemented here:
 *   draft  → computing (this action)
 *   computing → computed (the batch's `then` finalizer)
 *
 * Approval / posting / voiding land in Week 10. This action is the only
 * sanctioned producer of `PayrollRun` rows; downstream UI (Stage C) calls
 * it via an admin controller endpoint.
 */
final class GeneratePayrollRunAction
{
    /**
     * Create a payroll run for the given period and dispatch one
     * {@see ComputeEmployeePayslipJob} per active employee.
     *
     * Returns the created `PayrollRun`. If there are no active employees
     * the run is still created and immediately marked `computed` with all
     * totals zeroed — keeps callers (and the UI) on a uniform happy path.
     */
    public function execute(PayPeriod $period): PayrollRun
    {
        // Period locking: refuse to start a new run when the latest run on
        // this period is approved or posted. A voided run doesn't lock —
        // admins can re-run after voiding.
        $latest = PayrollRun::query()
            ->where('pay_period_id', $period->id)
            ->whereIn('status', [PayrollRun::STATUS_APPROVED, PayrollRun::STATUS_POSTED])
            ->exists();

        if ($latest) {
            throw new \DomainException(sprintf(
                'Pay period [%s] is locked by an approved or posted payroll run. Void the existing run before re-generating.',
                $period->code,
            ));
        }

        $employeeIds = EmployeeProfile::query()
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $run = DB::transaction(fn (): PayrollRun => PayrollRun::query()->create([
            'pay_period_id' => $period->id,
            'status' => count($employeeIds) === 0
                ? PayrollRun::STATUS_COMPUTED
                : PayrollRun::STATUS_COMPUTING,
            'total_employees' => count($employeeIds),
            'started_at' => now(),
            'computed_at' => count($employeeIds) === 0 ? now() : null,
        ]));

        if (count($employeeIds) === 0) {
            return $run;
        }

        $jobs = array_map(
            static fn (int $profileId): ComputeEmployeePayslipJob => new ComputeEmployeePayslipJob(
                payrollRunId: $run->id,
                employeeProfileId: $profileId,
            ),
            $employeeIds,
        );

        Bus::batch($jobs)
            ->name(sprintf('payroll-run-%d', $run->id))
            ->then(function (Batch $batch) use ($run): void {
                self::finalize($run);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($run): void {
                // A single job failure cancels the batch by default. Snap
                // the run back to `draft` so the UI shows it didn't compute;
                // an admin can re-run after addressing the underlying error.
                $run->forceFill(['status' => PayrollRun::STATUS_DRAFT])->save();
            })
            ->dispatch();

        return $run;
    }

    /**
     * Roll up payslip totals onto the run and flip status to `computed`.
     * Called from the batch's `then` callback once every per-employee job
     * lands successfully.
     */
    public static function finalize(PayrollRun $run): void
    {
        $totals = $run->payslips()
            ->selectRaw(
                'COUNT(*) as employee_count, '.
                'COALESCE(SUM(total_employee_deductions_centavos), 0) as ee, '.
                'COALESCE(SUM(total_employer_contributions_centavos), 0) as er, '.
                'COALESCE(SUM(net_pay_centavos), 0) as net'
            )
            ->first();

        $run->forceFill([
            'status' => PayrollRun::STATUS_COMPUTED,
            'total_employees' => (int) ($totals?->employee_count ?? 0),
            'total_employee_deductions_centavos' => (int) ($totals?->ee ?? 0),
            'total_employer_contributions_centavos' => (int) ($totals?->er ?? 0),
            'total_net_pay_centavos' => (int) ($totals?->net ?? 0),
            'computed_at' => now(),
        ])->save();
    }
}
