<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeIndexRequest;
use App\Http\Requests\EmployeeProfileUpdateRequest;
use App\Models\Lms\Department;
use App\Models\Pas\Allowance;
use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeRepositoryInterface $repo,
    ) {}

    public function index(EmployeeIndexRequest $request): Response
    {
        $filters = $request->filters();
        $perPage = (int) ($request->validated('per_page') ?? 25);

        $employees = $this->repo->paginate($filters, $perPage);

        return Inertia::render('employees/index', [
            'employees' => $employees,
            'filters' => $request->only(['search', 'status', 'department_id', 'employment_classification', 'sort_by', 'sort_dir', 'per_page']),
            'departmentOptions' => Department::query()->orderBy('name')->get(['id', 'name']),
            'employmentTypeOptions' => EmployeeProfile::employmentTypeOptions(),
        ]);
    }

    public function show(int $staffId): Response
    {
        $detail = $this->repo->findDetailByStaffId($staffId);

        if ($detail === null) {
            abort(404);
        }

        // Authorize against the concrete profile when one exists; fall back to
        // the class-level viewAny check when no profile has been set up yet.
        if ($detail->profile !== null) {
            Gate::authorize('view', $detail->profile);
        } else {
            Gate::authorize('viewAny', EmployeeProfile::class);
        }

        $profile = $detail->profile;

        return Inertia::render('employees/show', [
            'employee' => $detail,
            'employmentTypeOptions' => EmployeeProfile::employmentTypeOptions(),
            // Week 7, Chunk 6 — per-employee subscription rows for the four
            // cards that replace the deductions placeholder. When the staff
            // does not yet have a payroll profile, every collection is empty
            // because the FK columns target pas_employee_profiles.
            'deductions' => $this->loadDeductions($profile),
            'allowances' => $this->loadAllowances($profile),
            'loans' => $this->loadLoans($profile),
            'pendingAdjustments' => $this->loadPendingAdjustments($profile),
            // Catalog options surfaced for the create/edit sheets so the
            // frontend never has to hit a separate endpoint to resolve them.
            // Filtered to active rows only so HR cannot subscribe an employee
            // to a retired catalog entry.
            'deductionTypeOptions' => DeductionType::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'is_taxable', 'calc_method', 'source']),
            'allowanceOptions' => Allowance::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'is_taxable', 'is_de_minimis', 'de_minimis_cap_centavos', 'default_amount_centavos']),
        ]);
    }

    /**
     * Load all deduction subscriptions for a profile, eager-loading the
     * catalog row so the card can render `deduction_type.name` without an
     * N+1. Returns empty when the profile is null.
     *
     * @return Collection<int, EmployeeDeduction>
     */
    private function loadDeductions(?EmployeeProfile $profile): Collection
    {
        if ($profile === null) {
            return collect();
        }

        return EmployeeDeduction::query()
            ->where('employee_profile_id', $profile->id)
            ->with(['deductionType:id,code,name,is_taxable,calc_method,source'])
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * @return Collection<int, EmployeeAllowance>
     */
    private function loadAllowances(?EmployeeProfile $profile): Collection
    {
        if ($profile === null) {
            return collect();
        }

        return EmployeeAllowance::query()
            ->where('employee_profile_id', $profile->id)
            ->with(['allowance:id,code,name,is_taxable,is_de_minimis,de_minimis_cap_centavos'])
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * @return Collection<int, EmployeeLoan>
     */
    private function loadLoans(?EmployeeProfile $profile): Collection
    {
        if ($profile === null) {
            return collect();
        }

        return EmployeeLoan::query()
            ->where('employee_profile_id', $profile->id)
            ->orderBy('closed_on') // open loans (NULL) sort first
            ->orderBy('started_on', 'desc')
            ->get();
    }

    /**
     * Pending adjustments = current and future. The adjustments card shows
     * upcoming rows that have not yet been swept into a payroll run; past
     * rows live in the audit trail / payslip history (out of scope here).
     *
     * `today()` uses the request-time date which is fine for an admin list —
     * the compute path is the source of truth at run time and applies the
     * stricter `period.start() <= applies_on <= period.end()` rule.
     *
     * @return Collection<int, PayrollAdjustment>
     */
    private function loadPendingAdjustments(?EmployeeProfile $profile): Collection
    {
        if ($profile === null) {
            return collect();
        }

        return PayrollAdjustment::query()
            ->where('employee_profile_id', $profile->id)
            ->whereDate('applies_on', '>=', CarbonImmutable::now()->startOfDay())
            ->orderBy('applies_on')
            ->get();
    }

    public function store(int $staffId): RedirectResponse
    {
        Gate::authorize('create', EmployeeProfile::class);

        // Confirm the staff exists and is in the role allowlist before
        // attempting to create a profile. Without this, a malicious request
        // could create profiles for staff outside the payroll scope.
        $detail = $this->repo->findDetailByStaffId($staffId);

        if ($detail === null) {
            abort(404);
        }

        $this->repo->firstOrCreateForStaff($staffId);

        return back()->with('success', 'Payroll profile created.');
    }

    public function update(EmployeeProfileUpdateRequest $request, int $staffId): RedirectResponse
    {
        $profile = EmployeeProfile::query()
            ->where('lms_staff_id', $staffId)
            ->firstOrFail();

        Gate::authorize('update', $profile);

        $profile->fill($request->validated())->save();

        return back()->with('success', 'Profile updated.');
    }

    /**
     * Return the EmployeeProfile (and minimal LMS identity) as JSON, used by
     * the directory's quick-edit dropdown to lazy-load form data without
     * navigating to the show page.
     */
    public function profileJson(int $staffId): JsonResponse
    {
        $detail = $this->repo->findDetailByStaffId($staffId);

        if ($detail === null) {
            abort(404);
        }

        if ($detail->profile !== null) {
            Gate::authorize('view', $detail->profile);
        } else {
            Gate::authorize('viewAny', EmployeeProfile::class);
        }

        return response()->json([
            'employee' => [
                'lms_staff_id' => $detail->lms_staff_id,
                'full_name' => $detail->full_name,
                'staff_no' => $detail->staff_no,
            ],
            'profile' => $detail->profile,
        ]);
    }
}
