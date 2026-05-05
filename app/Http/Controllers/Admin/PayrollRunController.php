<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Payroll\GeneratePayrollRunAction;
use App\Http\Controllers\Controller;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin surface for payroll runs (Phase 3 Week 9).
 *
 * - index: list runs grouped by status (most recent first)
 * - create: pick an open PayPeriod, click Generate
 * - store: dispatches GeneratePayrollRunAction; redirects to show
 * - show: run detail with totals + payslips + computing-state progress
 *
 * Voiding lives in Week 10's approval workflow — not exposed here yet.
 */
final class PayrollRunController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', PayrollRun::class);

        $runs = PayrollRun::query()
            ->with(['payPeriod', 'approvedBy:id,name', 'voidedBy:id,name'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (PayrollRun $run): array => self::serialiseRun($run));

        return Inertia::render('admin/payroll-runs/index', [
            'runs' => $runs->all(),
            'can' => [
                'create' => Gate::allows('create', PayrollRun::class),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PayrollRun::class);

        $periods = PayPeriod::query()
            ->where('status', PayPeriod::STATUS_OPEN)
            ->orderBy('start_date', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (PayPeriod $p): array => [
                'id' => $p->id,
                'code' => $p->code,
                'frequency' => $p->frequency,
                'start_date' => $p->start_date->toDateString(),
                'end_date' => $p->end_date->toDateString(),
            ]);

        return Inertia::render('admin/payroll-runs/create', [
            'periods' => $periods->all(),
        ]);
    }

    public function store(Request $request, GeneratePayrollRunAction $action): RedirectResponse
    {
        Gate::authorize('create', PayrollRun::class);

        $data = $request->validate([
            'pay_period_id' => [
                'required',
                'integer',
                Rule::exists('pas_pay_periods', 'id')->where('status', PayPeriod::STATUS_OPEN),
            ],
        ]);

        $period = PayPeriod::query()->findOrFail($data['pay_period_id']);
        $run = $action->execute($period);

        return redirect()
            ->route('admin.payroll-runs.show', $run->id)
            ->with('success', 'Payroll run generation started.');
    }

    public function show(PayrollRun $payrollRun): Response
    {
        Gate::authorize('view', $payrollRun);

        $payrollRun->load(['payPeriod', 'approvedBy:id,name', 'voidedBy:id,name']);
        $payslips = $payrollRun
            ->payslips()
            ->orderBy('lms_staff_id')
            ->limit(500)
            ->get()
            ->map(fn ($p): array => [
                'id' => $p->id,
                'lms_staff_id' => $p->lms_staff_id,
                'gross_pay_centavos' => $p->gross_pay_centavos,
                'total_employee_deductions_centavos' => $p->total_employee_deductions_centavos,
                'total_employer_contributions_centavos' => $p->total_employer_contributions_centavos,
                'net_pay_centavos' => $p->net_pay_centavos,
                'taxable_income_centavos' => $p->taxable_income_centavos,
                'applied_exemptions' => $p->applied_exemptions ?? [],
            ]);

        return Inertia::render('admin/payroll-runs/show', [
            'run' => self::serialiseRun($payrollRun),
            'payslips' => $payslips->all(),
            'progress' => [
                'persisted_payslips' => $payslips->count(),
                'total_employees' => $payrollRun->total_employees,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serialiseRun(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'total_employees' => $run->total_employees,
            'total_employee_deductions_centavos' => $run->total_employee_deductions_centavos,
            'total_employer_contributions_centavos' => $run->total_employer_contributions_centavos,
            'total_net_pay_centavos' => $run->total_net_pay_centavos,
            'started_at' => $run->started_at?->toIso8601String(),
            'computed_at' => $run->computed_at?->toIso8601String(),
            'approved_at' => $run->approved_at?->toIso8601String(),
            'voided_at' => $run->voided_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'pay_period' => $run->payPeriod ? [
                'id' => $run->payPeriod->id,
                'code' => $run->payPeriod->code,
                'frequency' => $run->payPeriod->frequency,
                'start_date' => $run->payPeriod->start_date->toDateString(),
                'end_date' => $run->payPeriod->end_date->toDateString(),
            ] : null,
            'approved_by' => $run->approvedBy ? [
                'id' => $run->approvedBy->id,
                'name' => $run->approvedBy->name,
            ] : null,
            'voided_by' => $run->voidedBy ? [
                'id' => $run->voidedBy->id,
                'name' => $run->voidedBy->name,
            ] : null,
        ];
    }
}
