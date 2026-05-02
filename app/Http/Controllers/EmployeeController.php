<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeIndexRequest;
use App\Http\Requests\EmployeeProfileUpdateRequest;
use App\Models\Lms\Department;
use App\Models\Pas\EmployeeProfile;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

        return Inertia::render('employees/show', [
            'employee' => $detail,
            'employmentTypeOptions' => EmployeeProfile::employmentTypeOptions(),
        ]);
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
