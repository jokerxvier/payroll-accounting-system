<?php

declare(strict_types=1);

namespace App\Jobs\Payroll;

use App\Actions\Payroll\BuildBulkPayslipsZipAction;
use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\Pas\School;
use App\Services\SchoolLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Renders one payslip's PDF and writes it to a per-run temp directory.
 * Dispatched in a batch by {@see BuildBulkPayslipsZipAction};
 * the batch's `then` callback assembles the directory into a zip.
 *
 * Output path: `bulk-pdf-tmp/{run_id}/staff-{lms_staff_id}.pdf` on the
 * default disk. Files are wiped after the zip lands.
 */
final class RenderPayslipPdfJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $payrollRunId,
        public readonly int $payslipId,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $payslip = Payslip::query()->findOrFail($this->payslipId);
        $run = PayrollRun::query()->with('payPeriod')->findOrFail($this->payrollRunId);

        $vm = self::buildViewModel($run, $payslip);

        $relativePath = sprintf(
            'bulk-pdf-tmp/%d/%s',
            $run->id,
            self::pdfFilename(
                $vm['employee']['full_name'] ?? null,
                $vm['employee']['staff_no'] ?? null,
                $payslip->lms_staff_id,
            ),
        );

        $bytes = Pdf::loadView('payslips.pdf', $vm)->setPaper('a4')->output();
        Storage::put($relativePath, $bytes);
    }

    /**
     * Build the per-payslip PDF filename: {slug-of-name}-{staff_number}.pdf.
     *
     * Zip entries take this basename verbatim (see
     * BuildBulkPayslipsZipAction::assembleZip). Falls back to stable, unique
     * tokens when the staff name or number is missing so two payslips can
     * never collide within a run's temp dir — the numeric lms_staff_id is
     * unique per payslip and backstops a missing staff number.
     */
    public static function pdfFilename(?string $fullName, int|string|null $staffNo, int $lmsStaffId): string
    {
        $nameSlug = Str::slug((string) $fullName) ?: 'employee';
        $staffNumber = Str::slug((string) $staffNo) ?: (string) $lmsStaffId;

        return sprintf('%s-%s.pdf', $nameSlug, $staffNumber);
    }

    /**
     * Build the same view-model the controller's payslipViewModel() uses.
     * Duplicated here (small) so the queue worker doesn't need to load the
     * controller class. Keeping the two in lockstep is enforced by the
     * shared Blade template — any field the template references must
     * appear in both VMs.
     *
     * @return array<string, mixed>
     */
    public static function buildViewModel(PayrollRun $run, Payslip $payslip): array
    {
        $staff = Staff::query()
            ->where('id', $payslip->lms_staff_id)
            ->first(['id', 'full_name', 'staff_no', 'email']);

        $profile = EmployeeProfile::query()
            ->where('lms_staff_id', $payslip->lms_staff_id)
            ->first();

        $auditLines = $payslip->audit_lines ?? [];

        return [
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'pay_period' => $run->payPeriod ? [
                    'code' => $run->payPeriod->code,
                    'start_date' => $run->payPeriod->start_date->toDateString(),
                    'end_date' => $run->payPeriod->end_date->toDateString(),
                ] : null,
            ],
            'payslip' => [
                'id' => $payslip->id,
                'lms_staff_id' => $payslip->lms_staff_id,
                'gross_pay_centavos' => $payslip->gross_pay_centavos,
                'total_employee_deductions_centavos' => $payslip->total_employee_deductions_centavos,
                'total_employer_contributions_centavos' => $payslip->total_employer_contributions_centavos,
                'net_pay_centavos' => $payslip->net_pay_centavos,
                'taxable_income_centavos' => $payslip->taxable_income_centavos,
                'applied_exemptions' => $payslip->applied_exemptions ?? [],
                'computed_at_formatted' => $payslip->computed_at?->format('F j, Y'),
            ],
            'employee' => [
                'lms_staff_id' => $payslip->lms_staff_id,
                'staff_no' => $staff?->staff_no,
                'full_name' => $staff?->full_name,
                'email' => $staff?->email,
                'tin' => $profile?->tin,
                'sss_number' => $profile?->sss_number,
                'philhealth_number' => $profile?->philhealth_number,
                'pagibig_number' => $profile?->pagibig_number,
            ],
            'earnings' => array_values(array_filter(
                $auditLines,
                fn (array $l): bool => ($l['bucket'] ?? null) === 'earning',
            )),
            'deductions' => array_values(array_filter(
                $auditLines,
                fn (array $l): bool => ($l['bucket'] ?? null) === 'employee_deduction',
            )),
            'employer_lines' => array_values(array_filter(
                $auditLines,
                fn (array $l): bool => ($l['bucket'] ?? null) === 'employer_contribution',
            )),
            // The employer, which this document did not name at all before.
            // Resolved in BOTH view models on purpose — they are deliberately
            // duplicated, and a field the template reads that appears in only
            // one of them throws an undefined variable on the other path.
            'school' => (function (): array {
                $tenant = Tenant::current();
                $school = $tenant instanceof School ? $tenant : null;

                return [
                    'name' => $school === null
                        ? null
                        : ($school->registered_name ?? $school->name),
                    // A payslip is routinely handed to a bank or a landlord as
                    // proof of employment, so the employer has to be
                    // identifiable on it and not merely named.
                    'tin' => $school?->tin,
                    'address' => $school?->business_address,
                    'logo' => app(SchoolLogo::class)->dataUri($school),
                ];
            })(),
        ];
    }
}
