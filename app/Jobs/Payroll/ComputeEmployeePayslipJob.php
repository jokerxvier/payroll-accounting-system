<?php

declare(strict_types=1);

namespace App\Jobs\Payroll;

use App\Actions\Payroll\GeneratePayrollRunAction;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Services\Payroll\PayrollComputationService;
use App\Services\Payroll\PayrollLineItem;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Compute a single employee's payslip and persist it under a payroll run.
 *
 * Dispatched by {@see GeneratePayrollRunAction} as a
 * batch — one job per active employee per run. Idempotent at the DB layer
 * via the unique (payroll_run_id, employee_profile_id) constraint, so a
 * retry that races a successful first attempt fails fast at insert.
 *
 * The job snapshots the engine's full result (totals + audit lines + applied
 * exemptions) into a `Payslip` row. History stays stable even if the
 * employee profile, contribution rates, or exemption list change later.
 */
final class ComputeEmployeePayslipJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $payrollRunId,
        public readonly int $employeeProfileId,
    ) {}

    public function handle(PayrollComputationService $engine): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $run = PayrollRun::query()->findOrFail($this->payrollRunId);
        $profile = EmployeeProfile::query()->findOrFail($this->employeeProfileId);

        $result = $engine->compute($profile, $run->payPeriod->toPayPeriodInput());

        Payslip::query()->updateOrCreate(
            [
                'payroll_run_id' => $run->id,
                'employee_profile_id' => $profile->id,
            ],
            [
                'lms_staff_id' => $profile->lms_staff_id,
                'gross_pay_centavos' => $result->grossPay->centavos(),
                'total_employee_deductions_centavos' => $result->totalEmployeeDeductions->centavos(),
                'total_employer_contributions_centavos' => $result->totalEmployerContributions->centavos(),
                'net_pay_centavos' => $result->netPay->centavos(),
                'taxable_income_centavos' => $result->taxableIncome->centavos(),
                'audit_lines' => self::serialiseAuditLines($result->auditLines),
                'applied_exemptions' => $profile->exempted_contribution_codes ?? [],
                'computed_at' => now(),
            ],
        );
    }

    /**
     * Flatten PayrollLineItem instances into JSON-storable arrays. The
     * Payslip model's `hydratedAuditLines()` reverses this for the renderer.
     *
     * @param  list<PayrollLineItem>  $lines
     * @return list<array{code: string, label: string, amount: int, bucket: string, meta: array<string, mixed>|null}>
     */
    private static function serialiseAuditLines(array $lines): array
    {
        return array_values(array_map(
            fn (PayrollLineItem $line): array => [
                'code' => $line->code,
                'label' => $line->label,
                'amount' => $line->amount->centavos(),
                'bucket' => $line->bucket,
                'meta' => $line->meta,
            ],
            $lines,
        ));
    }
}
