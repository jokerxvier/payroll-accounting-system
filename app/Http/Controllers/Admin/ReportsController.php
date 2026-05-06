<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exports\EmployeeHistoryReportExport;
use App\Exports\PayrollSummaryReportExport;
use App\Http\Controllers\Controller;
use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 4 W13 — Reports module.
 *
 * Stage A: payroll summary by pay period — aggregates payslip totals
 * (gross, employee deductions, employer contributions, net pay) for
 * every payroll run that fell inside the requested date range.
 *
 * Authorization is broader than the admin write surfaces: super-admin,
 * payroll-officer, and hr can all view reports because they're read-only
 * and analytical. Auditor is added later when Week 14's audit-log viewer
 * lands. Employees never see reports.
 */
final class ReportsController extends Controller
{
    /** @var list<string> */
    private const REPORT_ROLES = ['super-admin', 'payroll-officer', 'hr'];

    public function payrollSummary(Request $request): Response
    {
        $this->authorizeReports();

        [$from, $to] = $this->resolveDateRange($request);
        $rows = $this->buildPayrollSummaryRows($from, $to);

        return Inertia::render('admin/reports/payroll-summary', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'rows' => $rows,
            'totals' => self::summariseTotals($rows),
        ]);
    }

    public function payrollSummaryExport(Request $request): BinaryFileResponse
    {
        $this->authorizeReports();

        [$from, $to] = $this->resolveDateRange($request);
        $rows = $this->buildPayrollSummaryRows($from, $to);

        $filename = sprintf(
            'payroll-summary_%s_%s.xlsx',
            $from->toDateString(),
            $to->toDateString(),
        );

        return Excel::download(new PayrollSummaryReportExport($rows, $from, $to), $filename);
    }

    public function employeeHistory(Request $request): Response
    {
        $this->authorizeReports();

        $staffId = $request->integer('employee') ?: null;
        $employee = null;
        $rows = [];
        $totals = self::zeroHistoryTotals();

        if ($staffId !== null) {
            [$employee, $rows, $totals] = $this->buildEmployeeHistoryRows($staffId);
        }

        return Inertia::render('admin/reports/employee-history', [
            'filters' => ['employee' => $staffId],
            'employees' => $this->employeePickerOptions(),
            'employee' => $employee,
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }

    public function employeeHistoryExport(Request $request): BinaryFileResponse
    {
        $this->authorizeReports();

        $staffId = $request->integer('employee') ?: null;
        if ($staffId === null) {
            abort(422, 'Pick an employee before exporting.');
        }

        [$employee, $rows] = $this->buildEmployeeHistoryRows($staffId);

        $filename = sprintf(
            'employee-history_staff%d_%s.xlsx',
            $staffId,
            CarbonImmutable::now()->toDateString(),
        );

        return Excel::download(
            new EmployeeHistoryReportExport($employee, $rows),
            $filename,
        );
    }

    private function authorizeReports(): void
    {
        if (! auth()->user()?->hasAnyRole(self::REPORT_ROLES)) {
            abort(403);
        }
    }

    /**
     * @return list<array{lms_staff_id: int, full_name: string|null}>
     */
    private function employeePickerOptions(): array
    {
        $profileIds = EmployeeProfile::query()
            ->pluck('lms_staff_id')
            ->unique()
            ->values();

        $names = Staff::query()
            ->whereIn('id', $profileIds)
            ->get(['id', 'full_name'])
            ->keyBy('id');

        return $profileIds
            ->map(fn (int $id): array => [
                'lms_staff_id' => $id,
                'full_name' => $names->get($id)?->full_name,
            ])
            ->sortBy(fn (array $r): string => (string) ($r['full_name'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: list<array<string, mixed>>, 2: array<string, int>}
     */
    private function buildEmployeeHistoryRows(int $lmsStaffId): array
    {
        $staff = Staff::query()
            ->where('id', $lmsStaffId)
            ->first(['id', 'full_name', 'staff_no', 'email']);
        $employee = $staff ? [
            'lms_staff_id' => (int) $staff->id,
            'full_name' => $staff->full_name,
            'staff_no' => $staff->staff_no,
            'email' => $staff->email,
        ] : null;

        $payslips = Payslip::query()
            ->with(['payrollRun.payPeriod'])
            ->where('lms_staff_id', $lmsStaffId)
            ->whereHas('payrollRun', fn ($q) => $q->whereNot('status', PayrollRun::STATUS_VOIDED))
            ->orderBy('computed_at')
            ->get();

        $cumulativeGross = 0;
        $cumulativeDeductions = 0;
        $cumulativeNet = 0;

        $rows = $payslips->map(function (Payslip $p) use (&$cumulativeGross, &$cumulativeDeductions, &$cumulativeNet): array {
            $cumulativeGross += $p->gross_pay_centavos;
            $cumulativeDeductions += $p->total_employee_deductions_centavos;
            $cumulativeNet += $p->net_pay_centavos;

            return [
                'payslip_id' => $p->id,
                'run_id' => $p->payroll_run_id,
                'pay_period_code' => $p->payrollRun?->payPeriod?->code,
                'pay_period_start' => $p->payrollRun?->payPeriod?->start_date->toDateString(),
                'pay_period_end' => $p->payrollRun?->payPeriod?->end_date->toDateString(),
                'computed_at' => $p->computed_at?->toIso8601String(),
                'gross_pay_centavos' => $p->gross_pay_centavos,
                'total_employee_deductions_centavos' => $p->total_employee_deductions_centavos,
                'net_pay_centavos' => $p->net_pay_centavos,
                'cumulative_gross_centavos' => $cumulativeGross,
                'cumulative_deductions_centavos' => $cumulativeDeductions,
                'cumulative_net_centavos' => $cumulativeNet,
            ];
        })->values()->all();

        return [
            $employee,
            $rows,
            [
                'payslip_count' => count($rows),
                'gross_pay_centavos' => $cumulativeGross,
                'total_employee_deductions_centavos' => $cumulativeDeductions,
                'total_net_pay_centavos' => $cumulativeNet,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function zeroHistoryTotals(): array
    {
        return [
            'payslip_count' => 0,
            'gross_pay_centavos' => 0,
            'total_employee_deductions_centavos' => 0,
            'total_net_pay_centavos' => 0,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveDateRange(Request $request): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $defaultFrom = $today->startOfMonth();
        $defaultTo = $today->endOfMonth()->startOfDay();

        $from = $request->date('from')
            ? CarbonImmutable::parse((string) $request->input('from'))->startOfDay()
            : $defaultFrom;
        $to = $request->date('to')
            ? CarbonImmutable::parse((string) $request->input('to'))->startOfDay()
            : $defaultTo;

        // Defend against an inverted range — flip if needed.
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * Aggregates payslips by payroll run inside the date range. Voided
     * runs are excluded — they're historical noise in a summary view.
     *
     * @return list<array<string, mixed>>
     */
    private function buildPayrollSummaryRows(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $periods = PayPeriod::query()
            ->where('end_date', '>=', $from)
            ->where('start_date', '<=', $to)
            ->orderBy('start_date')
            ->pluck('id');

        $runs = PayrollRun::query()
            ->with(['payPeriod'])
            ->whereIn('pay_period_id', $periods)
            ->whereNot('status', PayrollRun::STATUS_VOIDED)
            ->orderByDesc('created_at')
            ->get();

        return $runs
            ->map(fn (PayrollRun $run): array => [
                'run_id' => $run->id,
                'status' => $run->status,
                'pay_period_code' => $run->payPeriod?->code,
                'pay_period_start' => $run->payPeriod?->start_date->toDateString(),
                'pay_period_end' => $run->payPeriod?->end_date->toDateString(),
                'employee_count' => Payslip::query()
                    ->where('payroll_run_id', $run->id)
                    ->count(),
                'gross_pay_centavos' => (int) Payslip::query()
                    ->where('payroll_run_id', $run->id)
                    ->sum('gross_pay_centavos'),
                'total_employee_deductions_centavos' => $run->total_employee_deductions_centavos,
                'total_employer_contributions_centavos' => $run->total_employer_contributions_centavos,
                'total_net_pay_centavos' => $run->total_net_pay_centavos,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private static function summariseTotals(array $rows): array
    {
        $totals = [
            'employee_count' => 0,
            'gross_pay_centavos' => 0,
            'total_employee_deductions_centavos' => 0,
            'total_employer_contributions_centavos' => 0,
            'total_net_pay_centavos' => 0,
        ];

        foreach ($rows as $row) {
            $totals['employee_count'] += $row['employee_count'];
            $totals['gross_pay_centavos'] += $row['gross_pay_centavos'];
            $totals['total_employee_deductions_centavos'] += $row['total_employee_deductions_centavos'];
            $totals['total_employer_contributions_centavos'] += $row['total_employer_contributions_centavos'];
            $totals['total_net_pay_centavos'] += $row['total_net_pay_centavos'];
        }

        return $totals;
    }
}
