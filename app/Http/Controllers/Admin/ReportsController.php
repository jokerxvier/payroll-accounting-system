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
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelWriter;
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
    // platform-admin is included so the cross-tenant operator can run
    // reports against the active tenant. Reports are scoped to the
    // current tenant via the BelongsToTenant trait on Payslip /
    // EmployeeProfile, so cross-tenant data isn't leaked.
    /** @var list<string> */
    private const REPORT_ROLES = ['platform-admin', 'super-admin', 'payroll-officer', 'hr'];

    /**
     * Export formats every report supports, per the Phase 4 acceptance
     * criterion "three reports export cleanly to all three formats".
     *
     * `xlsx` is first because it is the default: the original W13 export
     * links carried no `format` parameter, so omitting one must keep
     * returning a spreadsheet.
     *
     * @var list<string>
     */
    private const EXPORT_FORMATS = ['xlsx', 'csv', 'pdf'];

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

    public function payrollSummaryExport(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorizeReports();

        $format = $this->resolveExportFormat($request);
        [$from, $to] = $this->resolveDateRange($request);
        $rows = $this->buildPayrollSummaryRows($from, $to);

        $filename = sprintf(
            'payroll-summary_%s_%s.%s',
            $from->toDateString(),
            $to->toDateString(),
            $format,
        );

        if ($format === 'pdf') {
            // Landscape: ten money columns do not fit A4 portrait without
            // shrinking the type past readability.
            return Pdf::loadView('reports.payroll-summary-pdf', [
                'from' => $from,
                'to' => $to,
                'rows' => $rows,
                'totals' => self::summariseTotals($rows),
                'generatedAt' => CarbonImmutable::now(),
            ])->setPaper('a4', 'landscape')->download($filename);
        }

        // xlsx and csv share one export class — Maatwebsite picks the writer
        // from the third argument, so the row shape stays defined once.
        return Excel::download(
            new PayrollSummaryReportExport($rows, $from, $to),
            $filename,
            $this->writerTypeFor($format),
        );
    }

    public function employeeHistory(Request $request): Response
    {
        $this->authorizeReports();

        $staffId = $request->integer('employee') ?: null;
        $employee = null;
        $rows = [];
        $totals = self::zeroHistoryTotals();
        $ytdByYear = [];

        if ($staffId !== null) {
            [$employee, $rows, $totals, $ytdByYear] = $this->buildEmployeeHistoryRows($staffId);
        }

        return Inertia::render('admin/reports/employee-history', [
            'filters' => ['employee' => $staffId],
            'employees' => $this->employeePickerOptions(),
            'employee' => $employee,
            'rows' => $rows,
            'totals' => $totals,
            'ytd_by_year' => $ytdByYear,
        ]);
    }

    public function employeeHistoryExport(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorizeReports();

        $format = $this->resolveExportFormat($request);

        $staffId = $request->integer('employee') ?: null;
        if ($staffId === null) {
            abort(422, 'Pick an employee before exporting.');
        }

        [$employee, $rows, $totals, $ytdByYear] = $this->buildEmployeeHistoryRows($staffId);

        $filename = sprintf(
            'employee-history_staff%d_%s.%s',
            $staffId,
            CarbonImmutable::now()->toDateString(),
            $format,
        );

        if ($format === 'pdf') {
            return Pdf::loadView('reports.employee-history-pdf', [
                'employee' => $employee,
                'rows' => $rows,
                'totals' => $totals,
                'ytdByYear' => $ytdByYear,
                'generatedAt' => CarbonImmutable::now(),
            ])->setPaper('a4', 'landscape')->download($filename);
        }

        return Excel::download(
            new EmployeeHistoryReportExport($employee, $rows, $ytdByYear),
            $filename,
            $this->writerTypeFor($format),
        );
    }

    private function authorizeReports(): void
    {
        if (! auth()->user()?->hasAnyRole(self::REPORT_ROLES)) {
            abort(403);
        }
    }

    /**
     * Read and validate the requested export format.
     *
     * Defaults to `xlsx` so the pre-existing export links — which carry no
     * `format` parameter — keep returning a spreadsheet. An unrecognised
     * value is rejected rather than silently falling back, so a typo in a
     * hand-built URL surfaces instead of quietly handing back the wrong
     * file type.
     */
    private function resolveExportFormat(Request $request): string
    {
        $format = strtolower(trim((string) $request->query('format', 'xlsx')));

        if (! in_array($format, self::EXPORT_FORMATS, true)) {
            abort(422, sprintf(
                "Unsupported export format '%s'. Use one of: %s.",
                $format,
                implode(', ', self::EXPORT_FORMATS),
            ));
        }

        return $format;
    }

    /**
     * Map a format to the Maatwebsite writer constant. Only reached for the
     * spreadsheet formats — `pdf` is rendered through dompdf instead, which
     * gives a laid-out document rather than a spreadsheet-shaped one.
     */
    private function writerTypeFor(string $format): string
    {
        return $format === 'csv' ? ExcelWriter::CSV : ExcelWriter::XLSX;
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
     * @return array{0: array<string, mixed>|null, 1: list<array<string, mixed>>, 2: array<string, int>, 3: list<array<string, int>>}
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

        /** @var array<int, array<string, int>> $perYear */
        $perYear = [];

        $rows = $payslips->map(function (Payslip $p) use (&$cumulativeGross, &$cumulativeDeductions, &$cumulativeNet, &$perYear): array {
            $cumulativeGross += $p->gross_pay_centavos;
            $cumulativeDeductions += $p->total_employee_deductions_centavos;
            $cumulativeNet += $p->net_pay_centavos;

            // YTD aggregation by calendar year of computed_at. Falls
            // back to the pay-period end_date when computed_at is null
            // (defensive — should never happen for persisted rows).
            $stampYear = $p->computed_at
                ? (int) $p->computed_at->year
                : (int) ($p->payrollRun?->payPeriod?->end_date?->year ?? CarbonImmutable::now()->year);

            $perYear[$stampYear] ??= [
                'payslip_count' => 0,
                'gross_pay_centavos' => 0,
                'total_employee_deductions_centavos' => 0,
                'total_employer_contributions_centavos' => 0,
                'total_net_pay_centavos' => 0,
            ];
            $perYear[$stampYear]['payslip_count']++;
            $perYear[$stampYear]['gross_pay_centavos'] += $p->gross_pay_centavos;
            $perYear[$stampYear]['total_employee_deductions_centavos'] += $p->total_employee_deductions_centavos;
            $perYear[$stampYear]['total_employer_contributions_centavos'] += $p->total_employer_contributions_centavos;
            $perYear[$stampYear]['total_net_pay_centavos'] += $p->net_pay_centavos;

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

        // Sort years descending — most recent first matches how a user
        // skimming a YTD list reads it (this year, then last year, then
        // older).
        krsort($perYear);
        $ytdByYear = [];
        foreach ($perYear as $year => $totals) {
            $ytdByYear[] = ['year' => $year] + $totals;
        }

        return [
            $employee,
            $rows,
            [
                'payslip_count' => count($rows),
                'gross_pay_centavos' => $cumulativeGross,
                'total_employee_deductions_centavos' => $cumulativeDeductions,
                'total_net_pay_centavos' => $cumulativeNet,
            ],
            $ytdByYear,
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
