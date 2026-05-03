<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\EmployeeAllowanceStoreRequest;
use App\Http\Requests\Employees\EmployeeAllowanceUpdateRequest;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Per-employee allowance subscription endpoints (Week 7, Chunk 6).
 *
 * Same shape as `EmployeeDeductionController` — nested under
 * `/employees/{staffId}/allowances/{employeeAllowance?}`, write-only
 * (store / update / destroy). Index data flows through `EmployeeController::show`
 * and renders inside the `AllowancesCard` component.
 */
final class EmployeeAllowanceController extends Controller
{
    public function store(EmployeeAllowanceStoreRequest $request, int $staffId): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        Gate::authorize('view', $profile);
        Gate::authorize('create', EmployeeAllowance::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $data['employee_profile_id'] = $profile->id;

        DB::transaction(fn () => EmployeeAllowance::create($data));

        return back()->with('success', 'Allowance added.');
    }

    public function update(EmployeeAllowanceUpdateRequest $request, int $staffId, EmployeeAllowance $employeeAllowance): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        abort_unless($employeeAllowance->employee_profile_id === $profile->id, 404);

        Gate::authorize('view', $profile);
        Gate::authorize('update', $employeeAllowance);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        DB::transaction(fn () => $employeeAllowance->update($data));

        return back()->with('success', 'Allowance updated.');
    }

    public function destroy(int $staffId, EmployeeAllowance $employeeAllowance): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        abort_unless($employeeAllowance->employee_profile_id === $profile->id, 404);

        Gate::authorize('view', $profile);
        Gate::authorize('delete', $employeeAllowance);

        DB::transaction(fn () => $employeeAllowance->delete());

        return back()->with('success', 'Allowance removed.');
    }

    private function resolveProfile(int $staffId): EmployeeProfile
    {
        $profile = EmployeeProfile::query()
            ->where('lms_staff_id', $staffId)
            ->first();

        abort_unless($profile !== null, 404);

        return $profile;
    }
}
