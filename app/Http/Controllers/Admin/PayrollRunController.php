<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Payroll\ApprovePayrollRunAction;
use App\Actions\Payroll\GeneratePayrollRunAction;
use App\Actions\Payroll\PostPayrollRunAction;
use App\Actions\Payroll\SubmitPayrollRunForApprovalAction;
use App\Actions\Payroll\VoidPayrollRunAction;
use App\Http\Controllers\Controller;
use App\Models\Lms\Staff;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use DomainException;
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
            ->with([
                'payPeriod',
                'submittedBy:id,full_name',
                'approvedBy:id,full_name',
                'postedBy:id,full_name',
                'voidedBy:id,full_name',
            ])
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

        try {
            $run = $action->execute($period);
        } catch (DomainException $e) {
            return back()->withErrors(['pay_period_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.payroll-runs.show', $run->id)
            ->with('success', 'Payroll run generation started.');
    }

    public function show(PayrollRun $payrollRun): Response
    {
        Gate::authorize('view', $payrollRun);

        $payrollRun->load([
            'payPeriod',
            'submittedBy:id,full_name',
            'approvedBy:id,full_name',
            'postedBy:id,full_name',
            'voidedBy:id,full_name',
        ]);
        $rawPayslips = $payrollRun
            ->payslips()
            ->orderBy('lms_staff_id')
            ->limit(500)
            ->get();

        // One LMS query for the whole batch — no N+1. Read-only LMS connection
        // is enforced by the ReadOnlyModel base class. `full_name` is a real
        // column on sm_staffs (verified via Schema::getColumnListing), so we
        // select it directly rather than rely on an accessor — there's no
        // getFullNameAttribute that derives from first+last.
        $staffNames = Staff::query()
            ->whereIn('id', $rawPayslips->pluck('lms_staff_id')->unique())
            ->get(['id', 'full_name'])
            ->keyBy('id');

        $payslips = $rawPayslips->map(fn ($p): array => [
            'id' => $p->id,
            'lms_staff_id' => $p->lms_staff_id,
            'staff_name' => $staffNames->get((int) $p->lms_staff_id)?->full_name,
            'gross_pay_centavos' => $p->gross_pay_centavos,
            'total_employee_deductions_centavos' => $p->total_employee_deductions_centavos,
            'total_employer_contributions_centavos' => $p->total_employer_contributions_centavos,
            'net_pay_centavos' => $p->net_pay_centavos,
            'taxable_income_centavos' => $p->taxable_income_centavos,
            'applied_exemptions' => $p->applied_exemptions ?? [],
            'audit_lines' => $p->audit_lines ?? [],
        ]);

        return Inertia::render('admin/payroll-runs/show', [
            'run' => self::serialiseRun($payrollRun),
            'payslips' => $payslips->all(),
            'progress' => [
                'persisted_payslips' => $payslips->count(),
                'total_employees' => $payrollRun->total_employees,
            ],
            'can' => [
                'submit' => Gate::allows('submit', $payrollRun),
                'approve' => Gate::allows('approve', $payrollRun),
                'post' => Gate::allows('post', $payrollRun),
                'void' => Gate::allows('void', $payrollRun),
            ],
        ]);
    }

    public function submit(PayrollRun $payrollRun, SubmitPayrollRunForApprovalAction $action): RedirectResponse
    {
        Gate::authorize('submit', $payrollRun);
        $action->execute($payrollRun, (int) auth()->id());

        return redirect()
            ->route('admin.payroll-runs.show', $payrollRun->id)
            ->with('success', 'Payroll run submitted for approval.');
    }

    public function approve(PayrollRun $payrollRun, ApprovePayrollRunAction $action): RedirectResponse
    {
        Gate::authorize('approve', $payrollRun);
        $action->execute($payrollRun, (int) auth()->id());

        return redirect()
            ->route('admin.payroll-runs.show', $payrollRun->id)
            ->with('success', 'Payroll run approved.');
    }

    public function post(PayrollRun $payrollRun, PostPayrollRunAction $action): RedirectResponse
    {
        Gate::authorize('post', $payrollRun);
        $action->execute($payrollRun, (int) auth()->id());

        return redirect()
            ->route('admin.payroll-runs.show', $payrollRun->id)
            ->with('success', 'Payroll run posted. Final state.');
    }

    public function void(PayrollRun $payrollRun, VoidPayrollRunAction $action): RedirectResponse
    {
        Gate::authorize('void', $payrollRun);
        $action->execute($payrollRun, (int) auth()->id());

        return redirect()
            ->route('admin.payroll-runs.show', $payrollRun->id)
            ->with('success', 'Payroll run voided. Payslips remain visible for audit.');
    }

    /**
     * Standalone payslip view (Phase 3 W11 Stage A). HTML for screen +
     * print. PDF download lands in Stage B as a sibling route.
     */
    public function showPayslip(PayrollRun $payrollRun, Payslip $payslip): Response
    {
        Gate::authorize('view', $payrollRun);

        // Defensive: the URL pattern lets {payslip} be any id; ensure it
        // belongs to the {run} the user is viewing. Prevents leaking one
        // run's payslips through another run's URL.
        if ($payslip->payroll_run_id !== $payrollRun->id) {
            abort(404);
        }

        $payrollRun->load(['payPeriod']);

        $staff = Staff::query()
            ->where('id', $payslip->lms_staff_id)
            ->first(['id', 'full_name', 'staff_no', 'email', 'designation_id', 'department_id']);

        $profile = EmployeeProfile::query()
            ->where('lms_staff_id', $payslip->lms_staff_id)
            ->first();

        return Inertia::render('admin/payroll-runs/payslips/show', [
            'run' => self::serialiseRun($payrollRun),
            'payslip' => [
                'id' => $payslip->id,
                'lms_staff_id' => $payslip->lms_staff_id,
                'gross_pay_centavos' => $payslip->gross_pay_centavos,
                'total_employee_deductions_centavos' => $payslip->total_employee_deductions_centavos,
                'total_employer_contributions_centavos' => $payslip->total_employer_contributions_centavos,
                'net_pay_centavos' => $payslip->net_pay_centavos,
                'taxable_income_centavos' => $payslip->taxable_income_centavos,
                'audit_lines' => $payslip->audit_lines ?? [],
                'applied_exemptions' => $payslip->applied_exemptions ?? [],
                'computed_at' => $payslip->computed_at?->toIso8601String(),
            ],
            'employee' => [
                'lms_staff_id' => $payslip->lms_staff_id,
                'staff_no' => $staff?->staff_no,
                'full_name' => $staff?->full_name,
                'email' => $staff?->email,
                // Government IDs are encrypted at rest; the model's
                // encrypted cast decrypts them on read for the auth'd
                // super-admin. Falsy fallback when unset.
                'tin' => $profile?->tin,
                'sss_number' => $profile?->sss_number,
                'philhealth_number' => $profile?->philhealth_number,
                'pagibig_number' => $profile?->pagibig_number,
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
            'is_locked' => $run->isLocked(),
            'total_employees' => $run->total_employees,
            'total_employee_deductions_centavos' => $run->total_employee_deductions_centavos,
            'total_employer_contributions_centavos' => $run->total_employer_contributions_centavos,
            'total_net_pay_centavos' => $run->total_net_pay_centavos,
            'started_at' => $run->started_at?->toIso8601String(),
            'computed_at' => $run->computed_at?->toIso8601String(),
            'submitted_at' => $run->submitted_at?->toIso8601String(),
            'approved_at' => $run->approved_at?->toIso8601String(),
            'posted_at' => $run->posted_at?->toIso8601String(),
            'voided_at' => $run->voided_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'pay_period' => $run->payPeriod ? [
                'id' => $run->payPeriod->id,
                'code' => $run->payPeriod->code,
                'frequency' => $run->payPeriod->frequency,
                'start_date' => $run->payPeriod->start_date->toDateString(),
                'end_date' => $run->payPeriod->end_date->toDateString(),
            ] : null,
            'submitted_by' => $run->submittedBy ? [
                'id' => $run->submittedBy->id,
                'name' => $run->submittedBy->full_name,
            ] : null,
            'approved_by' => $run->approvedBy ? [
                'id' => $run->approvedBy->id,
                'name' => $run->approvedBy->full_name,
            ] : null,
            'posted_by' => $run->postedBy ? [
                'id' => $run->postedBy->id,
                'name' => $run->postedBy->full_name,
            ] : null,
            'voided_by' => $run->voidedBy ? [
                'id' => $run->voidedBy->id,
                'name' => $run->voidedBy->full_name,
            ] : null,
        ];
    }
}
