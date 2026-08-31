<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Payroll\ApprovePayrollRunAction;
use App\Actions\Payroll\BuildBulkPayslipsZipAction;
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
use App\Models\Pas\School;
use App\Services\SchoolLogo;
use App\Support\ContributionLedger;
use App\Support\PayslipLabel;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Spatie\Multitenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
                'submittedBy:id,name',
                'approvedBy:id,name',
                'postedBy:id,name',
                'voidedBy:id,name',
            ])
            // Latest first. Order by id (monotonic) so runs created in the
            // same second don't tie ambiguously the way created_at can.
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PayrollRun $run): array => self::serialiseRun($run));

        return Inertia::render('admin/payroll-runs/index', [
            'runs' => $runs,
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
            // Hide "finished" periods entirely — a period whose payroll has been
            // approved or posted is done and must not be re-generated. Mirrors
            // the guard in GeneratePayrollRunAction; runs are tenant-scoped via
            // the model.
            ->whereDoesntHave('payrollRuns', fn ($q) => $q
                ->whereIn('status', [PayrollRun::STATUS_APPROVED, PayrollRun::STATUS_POSTED]))
            ->orderBy('start_date', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (PayPeriod $p): array => [
                'id' => $p->id,
                'code' => $p->code,
                'frequency' => $p->frequency,
                'start_date' => $p->start_date->toDateString(),
                'end_date' => $p->end_date->toDateString(),
                // Finished (approved/posted) periods are excluded above, so a
                // returned period is always selectable.
                'locked_by_run' => null,
            ]);

        return Inertia::render('admin/payroll-runs/create', [
            'periods' => $periods->all(),
            // How many employees a run will process — lets the UI set scale
            // expectations up front. Mirrors GeneratePayrollRunAction's filter.
            'active_employee_count' => EmployeeProfile::query()
                ->where('is_active', true)
                ->count(),
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
        } catch (DomainException|InvalidArgumentException $e) {
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
            'submittedBy:id,name',
            'approvedBy:id,name',
            'postedBy:id,name',
            'voidedBy:id,name',
        ]);
        $payslips = $payrollRun
            ->payslips()
            ->orderBy('lms_staff_id')
            ->paginate(25)
            ->withQueryString();

        // One LMS query for the whole page — no N+1. Read-only LMS connection
        // is enforced by the ReadOnlyModel base class. `full_name` is a real
        // column on sm_staffs (verified via Schema::getColumnListing), so we
        // select it directly rather than rely on an accessor — there's no
        // getFullNameAttribute that derives from first+last.
        $staffNames = Staff::query()
            ->whereIn('id', collect($payslips->items())->pluck('lms_staff_id')->unique())
            ->get(['id', 'full_name'])
            ->keyBy('id');

        $payslips->through(fn ($p): array => [
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
            'payslips' => $payslips,
            'progress' => [
                // total() spans every page, so the computing progress bar
                // stays correct regardless of which page is being viewed.
                'persisted_payslips' => $payslips->total(),
                'total_employees' => $payrollRun->total_employees,
            ],
            // AND each gate check with the run's status predicate. The
            // platform-admin Gate::before short-circuit returns true for
            // every ability regardless of status, so without the predicate a
            // platform-admin would see all four buttons even on a posted or
            // voided run. The predicates are the single source of truth for
            // which transition is legal at the current status.
            'can' => [
                'submit' => Gate::allows('submit', $payrollRun) && $payrollRun->isSubmittable(),
                'approve' => Gate::allows('approve', $payrollRun) && $payrollRun->isApprovable(),
                'post' => Gate::allows('post', $payrollRun) && $payrollRun->isPostable(),
                'void' => Gate::allows('void', $payrollRun) && $payrollRun->isVoidable(),
                // DEMO: hard-delete is allowed regardless of status.
                'delete' => Gate::allows('delete', $payrollRun),
            ],
        ]);
    }

    public function submit(PayrollRun $payrollRun, SubmitPayrollRunForApprovalAction $action): RedirectResponse
    {
        Gate::authorize('submit', $payrollRun);

        try {
            $action->execute($payrollRun, (int) auth()->id());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.payroll-runs.show', $payrollRun->id)
            ->with('success', 'Payroll run submitted for approval.');
    }

    public function approve(PayrollRun $payrollRun, ApprovePayrollRunAction $action): RedirectResponse
    {
        Gate::authorize('approve', $payrollRun);

        try {
            $action->execute($payrollRun, (int) auth()->id());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.payroll-runs.show', $payrollRun->id)
            ->with('success', 'Payroll run approved.');
    }

    public function post(PayrollRun $payrollRun, PostPayrollRunAction $action): RedirectResponse
    {
        Gate::authorize('post', $payrollRun);

        try {
            $action->execute($payrollRun, (int) auth()->id());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.payroll-runs.show', $payrollRun->id)
            ->with('success', 'Payroll run posted. Final state.');
    }

    public function void(PayrollRun $payrollRun, VoidPayrollRunAction $action): RedirectResponse
    {
        Gate::authorize('void', $payrollRun);

        try {
            $action->execute($payrollRun, (int) auth()->id());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.payroll-runs.show', $payrollRun->id)
            ->with('success', 'Payroll run voided. Payslips remain visible for audit.');
    }

    /**
     * Hard-delete a payroll run. The client requires the operator to type
     * "delete" to confirm. Payslip rows are removed by the DB cascade on
     * `pas_payslips.payroll_run_id`; the only extra cleanup is the on-disk
     * bulk-PDF ZIP artefact plus its temp scratch directory.
     *
     * Note: this deliberately breaks the project's "void, don't delete"
     * convention — it exists for the demo. Voiding remains the audit-safe
     * path for real use.
     */
    public function destroy(PayrollRun $payrollRun): RedirectResponse
    {
        Gate::authorize('delete', $payrollRun);

        if ($payrollRun->bulk_pdf_zip_path !== null) {
            Storage::delete($payrollRun->bulk_pdf_zip_path);
        }
        Storage::deleteDirectory(BuildBulkPayslipsZipAction::tempDir($payrollRun));

        $payrollRun->delete();

        return redirect()
            ->route('admin.payroll-runs.index')
            ->with('success', 'Payroll run deleted.');
    }

    /**
     * Build the canonical payslip view-model used by both the Inertia
     * HTML page and the dompdf-rendered PDF. Single source of truth so
     * the two surfaces never drift.
     *
     * They drifted anyway, because the page was handed only a third of this
     * and split `audit_lines` itself. Both surfaces now render from the same
     * `earnings` / `deductions` / `employer_lines` this returns.
     *
     * @return array{
     *     run: array<string,mixed>,
     *     payslip: array<string,mixed>,
     *     employee: array<string,mixed>,
     *     earnings: list<array<string,mixed>>,
     *     deductions: list<array<string,mixed>>,
     *     employer_lines: list<array<string,mixed>>,
     *     school: array{name: string|null, tin: string|null, address: string|null, logo: string|null}
     * }
     */
    private function payslipViewModel(PayrollRun $run, Payslip $payslip): array
    {
        $run->load(['payPeriod']);

        $staff = Staff::query()
            ->where('id', $payslip->lms_staff_id)
            ->first(['id', 'full_name', 'staff_no', 'email', 'designation_id', 'department_id']);

        $profile = EmployeeProfile::query()
            ->where('lms_staff_id', $payslip->lms_staff_id)
            ->first();

        $auditLines = $payslip->audit_lines ?? [];
        $earnings = array_values(array_filter(
            $auditLines,
            fn (array $l): bool => ($l['bucket'] ?? null) === 'earning',
        ));
        $deductions = array_values(array_filter(
            $auditLines,
            fn (array $l): bool => ($l['bucket'] ?? null) === 'employee_deduction',
        ));
        $employerLines = array_values(array_filter(
            $auditLines,
            fn (array $l): bool => ($l['bucket'] ?? null) === 'employer_contribution',
        ));

        return [
            'run' => self::serialiseRun($run),
            'payslip' => [
                'id' => $payslip->id,
                'lms_staff_id' => $payslip->lms_staff_id,
                'gross_pay_centavos' => $payslip->gross_pay_centavos,
                'total_employee_deductions_centavos' => $payslip->total_employee_deductions_centavos,
                'total_employer_contributions_centavos' => $payslip->total_employer_contributions_centavos,
                'net_pay_centavos' => $payslip->net_pay_centavos,
                'taxable_income_centavos' => $payslip->taxable_income_centavos,
                'audit_lines' => $auditLines,
                'applied_exemptions' => $payslip->applied_exemptions ?? [],
                'computed_at' => $payslip->computed_at?->toIso8601String(),
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
            'earnings' => $earnings,
            'deductions' => $deductions,
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
            'employer_lines' => $employerLines,
        ];
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

        $vm = $this->payslipViewModel($payrollRun, $payslip);

        // The screen and the PDF are the same document in two media, so they
        // are handed the same figures rather than each deriving its own. The
        // page used to split `audit_lines` itself and print the raw codes,
        // which is how the two drifted apart in the first place.
        return Inertia::render('admin/payroll-runs/payslips/show', [
            'run' => $vm['run'],
            'payslip' => $vm['payslip'],
            'employee' => $vm['employee'],
            // The VM's logo is a base64 data URI, which dompdf needs and a
            // browser does not: sending it here would put ~300 KB of image
            // into the page payload on every visit instead of letting the
            // web server serve one cacheable file.
            'school' => [
                'name' => $vm['school']['name'],
                'tin' => $vm['school']['tin'],
                'address' => $vm['school']['address'],
                'logo_url' => app(SchoolLogo::class)->url(
                    Tenant::current() instanceof School ? Tenant::current() : null,
                ),
            ],
            'earnings' => PayslipLabel::humaniseLines($vm['earnings']),
            'deductions' => PayslipLabel::humaniseLines($vm['deductions']),
            'employerLines' => PayslipLabel::humaniseLines($vm['employer_lines']),
            'contributions' => ContributionLedger::build(
                $vm['deductions'],
                $vm['employer_lines'],
            ),
        ]);
    }

    /**
     * Stream the payslip as a downloadable PDF (Phase 3 W11 Stage B).
     * Renders the same view-model the Inertia page uses, through a
     * dedicated dompdf-friendly Blade template.
     */
    public function downloadPayslipPdf(PayrollRun $payrollRun, Payslip $payslip): HttpResponse
    {
        Gate::authorize('view', $payrollRun);

        if ($payslip->payroll_run_id !== $payrollRun->id) {
            abort(404);
        }

        $vm = $this->payslipViewModel($payrollRun, $payslip);

        $filename = sprintf(
            'payslip-%s-staff%d.pdf',
            $vm['run']['pay_period']['code'] ?? ('run'.$vm['run']['id']),
            $vm['payslip']['lms_staff_id'],
        );

        return Pdf::loadView('payslips.pdf', $vm)
            ->setPaper('a4')
            ->download($filename);
    }

    /**
     * Phase 3 W11 Stage C — kick off the bulk-payslips zip build.
     * Idempotent on the action side; a second click while a build is
     * still running is harmless (per-job idempotence at the storage
     * layer).
     */
    public function buildBulkPdfs(PayrollRun $payrollRun, BuildBulkPayslipsZipAction $action): RedirectResponse
    {
        Gate::authorize('view', $payrollRun);

        $action->execute($payrollRun);

        return redirect()
            ->route('admin.payroll-runs.show', $payrollRun->id)
            ->with('success', 'Building bulk payslips ZIP. Refresh in a moment to download.');
    }

    /**
     * Stream the assembled zip back to the user. 404 when the zip
     * hasn't been built (or has been deleted from disk).
     */
    public function downloadBulkPdfs(PayrollRun $payrollRun): BinaryFileResponse
    {
        Gate::authorize('view', $payrollRun);

        if ($payrollRun->bulk_pdf_zip_path === null) {
            abort(404, 'Bulk payslips ZIP has not been built yet.');
        }

        if (! Storage::exists($payrollRun->bulk_pdf_zip_path)) {
            abort(404, 'Bulk payslips ZIP is missing from storage.');
        }

        $filename = sprintf(
            'payslips-%s.zip',
            $payrollRun->payPeriod?->code ?? ('run'.$payrollRun->id),
        );

        return response()->download(
            (string) Storage::path($payrollRun->bulk_pdf_zip_path),
            $filename,
        );
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
            'bulk_pdf_built_at' => $run->bulk_pdf_built_at?->toIso8601String(),
            'has_bulk_pdf' => $run->bulk_pdf_zip_path !== null,
            // Phase 5 Slice 3 — whether the run reached the general ledger.
            // A posted run WITHOUT an entry is a real state, not an error:
            // the ledger posting is refused when the accounting period is
            // closed, and payroll is deliberately not blocked on that. The
            // UI needs to say so rather than imply the books are up to date.
            'ledger_posted_at' => $run->ledger_posted_at?->toIso8601String(),
            'journal_entry' => $run->journalEntry ? [
                'id' => $run->journalEntry->id,
                'entry_number' => $run->journalEntry->entry_number,
            ] : null,
            'pay_period' => $run->payPeriod ? [
                'id' => $run->payPeriod->id,
                'code' => $run->payPeriod->code,
                'frequency' => $run->payPeriod->frequency,
                'start_date' => $run->payPeriod->start_date->toDateString(),
                'end_date' => $run->payPeriod->end_date->toDateString(),
            ] : null,
            // Phase A.2: User relation now points at pas_users (column `name`).
            'submitted_by' => $run->submittedBy ? [
                'id' => $run->submittedBy->id,
                'name' => $run->submittedBy->name,
            ] : null,
            'approved_by' => $run->approvedBy ? [
                'id' => $run->approvedBy->id,
                'name' => $run->approvedBy->name,
            ] : null,
            'posted_by' => $run->postedBy ? [
                'id' => $run->postedBy->id,
                'name' => $run->postedBy->name,
            ] : null,
            'voided_by' => $run->voidedBy ? [
                'id' => $run->voidedBy->id,
                'name' => $run->voidedBy->name,
            ] : null,
        ];
    }
}
