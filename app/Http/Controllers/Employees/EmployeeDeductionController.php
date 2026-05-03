<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\EmployeeDeductionStoreRequest;
use App\Http\Requests\Employees\EmployeeDeductionUpdateRequest;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Per-employee deduction subscription endpoints (Week 7, Chunk 6).
 *
 * Nested under /employees/{staffId} — `staffId` is the LMS staff id, NOT the
 * pas_employee_profiles primary key. The profile is resolved from `staffId`
 * in each action, mirroring the existing top-level `EmployeeController` lookup.
 *
 * Endpoints:
 *   POST  /employees/{staffId}/deductions
 *   PATCH /employees/{staffId}/deductions/{employeeDeduction}
 *   DELETE /employees/{staffId}/deductions/{employeeDeduction}
 *
 * Index/show data flows through `EmployeeController::show` props rendered
 * inside the `DeductionsCard` component on the employee Show page; this
 * controller only handles writes.
 */
final class EmployeeDeductionController extends Controller
{
    public function store(EmployeeDeductionStoreRequest $request, int $staffId): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        Gate::authorize('view', $profile);
        Gate::authorize('create', EmployeeDeduction::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $data['employee_profile_id'] = $profile->id;

        DB::transaction(fn () => EmployeeDeduction::create($data));

        return back()->with('success', 'Deduction added.');
    }

    public function update(EmployeeDeductionUpdateRequest $request, int $staffId, EmployeeDeduction $employeeDeduction): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        // Defense in depth: verify the route-bound row actually belongs to
        // the staff in the URL. Without this check a payload could update an
        // unrelated employee's row by guessing its id.
        abort_unless($employeeDeduction->employee_profile_id === $profile->id, 404);

        Gate::authorize('view', $profile);
        Gate::authorize('update', $employeeDeduction);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        DB::transaction(fn () => $employeeDeduction->update($data));

        return back()->with('success', 'Deduction updated.');
    }

    public function destroy(int $staffId, EmployeeDeduction $employeeDeduction): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        abort_unless($employeeDeduction->employee_profile_id === $profile->id, 404);

        Gate::authorize('view', $profile);
        Gate::authorize('delete', $employeeDeduction);

        DB::transaction(fn () => $employeeDeduction->delete());

        return back()->with('success', 'Deduction removed.');
    }

    /**
     * Resolve the EmployeeProfile from the LMS staff id segment in the URL.
     * 404s when no profile exists for this staff — the per-employee subscription
     * tables only make sense once a payroll profile has been provisioned.
     */
    private function resolveProfile(int $staffId): EmployeeProfile
    {
        $profile = EmployeeProfile::query()
            ->where('lms_staff_id', $staffId)
            ->first();

        abort_unless($profile !== null, 404);

        return $profile;
    }
}
