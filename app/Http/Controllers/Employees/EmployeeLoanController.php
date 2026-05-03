<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employees;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employees\EmployeeLoanStoreRequest;
use App\Http\Requests\Employees\EmployeeLoanUpdateRequest;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Per-employee loan endpoints (Week 7, Chunk 6).
 *
 * Loans have stricter server-controlled fields than the other subscription
 * tables:
 *   - `outstanding_balance_centavos` is initialised from `principal_centavos`
 *     at create time and only mutated downstream by the Week-9 batch path
 *     via `EmployeeLoan::applyAmortization()`.
 *   - `lock_version` is initialised to 0 and only mutated by the same
 *     persistence path.
 *   - `started_on` is set on create; not editable via update.
 *   - `closed_on` is automatically stamped when the balance hits zero.
 *
 * The FormRequest already excludes the protected fields from its validated
 * output. The controller never trusts user-supplied keys for these columns
 * — the validated array is the source of truth.
 */
final class EmployeeLoanController extends Controller
{
    public function store(EmployeeLoanStoreRequest $request, int $staffId): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        Gate::authorize('view', $profile);
        Gate::authorize('create', EmployeeLoan::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $data['employee_profile_id'] = $profile->id;
        // Initialise the running balance from the principal — the persistence
        // path is the only writer of this column from here on.
        $data['outstanding_balance_centavos'] = $data['principal_centavos'];
        $data['lock_version'] = 0;

        DB::transaction(fn () => EmployeeLoan::create($data));

        return back()->with('success', 'Loan added.');
    }

    public function update(EmployeeLoanUpdateRequest $request, int $staffId, EmployeeLoan $employeeLoan): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        abort_unless($employeeLoan->employee_profile_id === $profile->id, 404);

        Gate::authorize('view', $profile);
        Gate::authorize('update', $employeeLoan);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // The FormRequest already excludes protected fields, but the
        // validated() return is the only thing passed to update(). No extra
        // forget() is needed here.
        DB::transaction(fn () => $employeeLoan->update($data));

        return back()->with('success', 'Loan updated.');
    }

    public function destroy(int $staffId, EmployeeLoan $employeeLoan): RedirectResponse
    {
        $profile = $this->resolveProfile($staffId);

        abort_unless($employeeLoan->employee_profile_id === $profile->id, 404);

        Gate::authorize('view', $profile);
        Gate::authorize('delete', $employeeLoan);

        DB::transaction(fn () => $employeeLoan->delete());

        return back()->with('success', 'Loan removed.');
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
