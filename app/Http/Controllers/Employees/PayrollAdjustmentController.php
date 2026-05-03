<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\PayrollAdjustmentStoreRequest;
use App\Http\Requests\Employees\PayrollAdjustmentUpdateRequest;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * One-off payroll adjustment endpoints (Week 7, Chunk 6).
 *
 * Same shape as the other Employees/* controllers — nested under
 * `/employees/{staffId}/adjustments/{payrollAdjustment?}`, write-only.
 *
 * `applied_pay_period_id` is server-controlled: today it is always null
 * (set by the Week-9 batch backfill once `pas_pay_periods` ships). The
 * FormRequest does not validate it and the controller does not accept it.
 */
final class PayrollAdjustmentController extends Controller
{
    public function store(PayrollAdjustmentStoreRequest $request, int $staffId): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        Gate::authorize('view', $profile);
        Gate::authorize('create', PayrollAdjustment::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $data['employee_profile_id'] = $profile->id;

        DB::transaction(fn () => PayrollAdjustment::create($data));

        return back()->with('success', 'Adjustment added.');
    }

    public function update(PayrollAdjustmentUpdateRequest $request, int $staffId, PayrollAdjustment $payrollAdjustment): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        abort_unless($payrollAdjustment->employee_profile_id === $profile->id, 404);

        Gate::authorize('view', $profile);
        Gate::authorize('update', $payrollAdjustment);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        DB::transaction(fn () => $payrollAdjustment->update($data));

        return back()->with('success', 'Adjustment updated.');
    }

    public function destroy(int $staffId, PayrollAdjustment $payrollAdjustment): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        abort_unless($payrollAdjustment->employee_profile_id === $profile->id, 404);

        Gate::authorize('view', $profile);
        Gate::authorize('delete', $payrollAdjustment);

        DB::transaction(fn () => $payrollAdjustment->delete());

        return back()->with('success', 'Adjustment removed.');
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
