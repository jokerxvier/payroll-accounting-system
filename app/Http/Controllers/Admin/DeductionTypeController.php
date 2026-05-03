<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeductionTypeStoreRequest;
use App\Http\Requests\Admin\DeductionTypeUpdateRequest;
use App\Models\Pas\DeductionType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin surface for the `pas_deduction_types` catalog (Week 7, Chunk 5a).
 *
 * v1 scope: super-admin only — gated per action via Gate::authorize against
 * DeductionTypePolicy.
 *
 * Catalog rows are mutable identity records (code, name, taxability, calc
 * method, source, active flag, notes). Per-employee subscriptions live on
 * `pas_employee_deductions` and reference this catalog by `deduction_type_id`
 * with restrictOnDelete — so destroy() soft-blocks when subscriptions exist
 * and surfaces a flash error directing the operator to toggle `is_active`
 * off instead. Toggling inactive lets historical payroll runs replay
 * faithfully while preventing the type from being added to new
 * subscriptions.
 *
 * The catalog is small (a few dozen rows even at scale) so index() returns
 * the full list ordered by code without pagination.
 */
final class DeductionTypeController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', DeductionType::class);

        $deductionTypes = DeductionType::query()
            ->orderBy('code')
            ->get();

        return Inertia::render('admin/deduction-types/index', [
            'deductionTypes' => $deductionTypes,
            'calcMethodOptions' => self::calcMethodOptions(),
            'sourceOptions' => self::sourceOptions(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', DeductionType::class);

        return Inertia::render('admin/deduction-types/create', [
            'calcMethodOptions' => self::calcMethodOptions(),
            'sourceOptions' => self::sourceOptions(),
        ]);
    }

    public function store(DeductionTypeStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', DeductionType::class);

        /** @var array{code: string, name: string, is_taxable: bool, calc_method: string, source: string, is_active: bool, notes?: ?string} $data */
        $data = $request->validated();

        $row = DB::transaction(fn (): DeductionType => DeductionType::create($data));

        return redirect()
            ->route('admin.deduction-types.index')
            ->with('success', "Deduction type '{$row->code}' created.");
    }

    public function edit(DeductionType $deductionType): Response
    {
        Gate::authorize('update', $deductionType);

        return Inertia::render('admin/deduction-types/edit', [
            'deductionType' => $deductionType,
            'calcMethodOptions' => self::calcMethodOptions(),
            'sourceOptions' => self::sourceOptions(),
        ]);
    }

    public function update(DeductionTypeUpdateRequest $request, DeductionType $deductionType): RedirectResponse
    {
        Gate::authorize('update', $deductionType);

        /** @var array{code: string, name: string, is_taxable: bool, calc_method: string, source: string, is_active: bool, notes?: ?string} $data */
        $data = $request->validated();

        DB::transaction(fn () => $deductionType->update($data));

        return redirect()
            ->route('admin.deduction-types.index')
            ->with('success', "Deduction type '{$deductionType->code}' updated.");
    }

    public function destroy(DeductionType $deductionType): RedirectResponse
    {
        Gate::authorize('delete', $deductionType);

        // Soft-block: any per-employee subscription referencing this catalog
        // row pins it for historical payroll replay. The DB FK uses
        // restrictOnDelete so a hard delete would throw a SQL error anyway —
        // we surface a friendly flash message and direct the operator to
        // toggle `is_active` off instead.
        if ($deductionType->employeeDeductions()->exists()) {
            return redirect()
                ->route('admin.deduction-types.index')
                ->with('error', 'Cannot delete a deduction type that has active employee subscriptions. Toggle is_active off instead.');
        }

        DB::transaction(fn () => $deductionType->delete());

        return redirect()
            ->route('admin.deduction-types.index')
            ->with('success', "Deduction type '{$deductionType->code}' deleted.");
    }

    /**
     * @return array<string, string>
     */
    private static function calcMethodOptions(): array
    {
        return [
            DeductionType::CALC_FIXED => 'Fixed amount',
            DeductionType::CALC_PERCENT => 'Percent of gross',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function sourceOptions(): array
    {
        return [
            DeductionType::SOURCE_EMPLOYEE => 'Employee only',
            DeductionType::SOURCE_EMPLOYER => 'Employer only',
            DeductionType::SOURCE_BOTH => 'Both employee and employer',
        ];
    }
}
