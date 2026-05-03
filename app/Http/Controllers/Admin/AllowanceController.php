<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AllowanceStoreRequest;
use App\Http\Requests\Admin\AllowanceUpdateRequest;
use App\Models\Pas\Allowance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin surface for the `pas_allowances` catalog (Week 7, Chunk 5a).
 *
 * v1 scope: super-admin only — gated per action via Gate::authorize against
 * AllowancePolicy.
 *
 * Catalog rows track allowance identity (code, name) plus tax behavior
 * (taxable flag, de-minimis flag, BIR cap) and a default amount. Per-employee
 * subscriptions live on `pas_employee_allowances` with restrictOnDelete — so
 * destroy() soft-blocks when subscriptions exist and directs the operator to
 * toggle `is_active` off instead.
 *
 * Conditional invariant on de_minimis_cap_centavos lives in the FormRequest:
 * REQUIRED when is_de_minimis=true, NULL otherwise (see Decision 5 in the
 * Week 7 plan). The cap may be NULL on a de-minimis row only if the BIR
 * specification is still pending — but the FormRequest forbids that path
 * for new/updated rows; only seeded rows can stay NULL until first edit.
 *
 * The catalog is small so index() returns the full list ordered by code
 * without pagination.
 */
final class AllowanceController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Allowance::class);

        $allowances = Allowance::query()
            ->orderBy('code')
            ->get();

        return Inertia::render('admin/allowances/index', [
            'allowances' => $allowances,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Allowance::class);

        return Inertia::render('admin/allowances/create');
    }

    public function store(AllowanceStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', Allowance::class);

        /** @var array{code: string, name: string, is_taxable: bool, is_de_minimis: bool, de_minimis_cap_centavos?: ?int, default_amount_centavos?: ?int, is_active: bool, notes?: ?string} $data */
        $data = $request->validated();

        $row = DB::transaction(fn (): Allowance => Allowance::create($data));

        return redirect()
            ->route('admin.allowances.index')
            ->with('success', "Allowance '{$row->code}' created.");
    }

    public function edit(Allowance $allowance): Response
    {
        Gate::authorize('update', $allowance);

        return Inertia::render('admin/allowances/edit', [
            'allowance' => $allowance,
        ]);
    }

    public function update(AllowanceUpdateRequest $request, Allowance $allowance): RedirectResponse
    {
        Gate::authorize('update', $allowance);

        /** @var array{code: string, name: string, is_taxable: bool, is_de_minimis: bool, de_minimis_cap_centavos?: ?int, default_amount_centavos?: ?int, is_active: bool, notes?: ?string} $data */
        $data = $request->validated();

        DB::transaction(fn () => $allowance->update($data));

        return redirect()
            ->route('admin.allowances.index')
            ->with('success', "Allowance '{$allowance->code}' updated.");
    }

    public function destroy(Allowance $allowance): RedirectResponse
    {
        Gate::authorize('delete', $allowance);

        // Soft-block when subscriptions exist — the DB FK is restrictOnDelete,
        // so a hard delete would throw a SQL error; we surface a friendly
        // flash message instead and direct the operator to toggle is_active
        // off so historical payroll runs remain replayable.
        if ($allowance->employeeAllowances()->exists()) {
            return redirect()
                ->route('admin.allowances.index')
                ->with('error', 'Cannot delete an allowance that has active employee subscriptions. Toggle is_active off instead.');
        }

        DB::transaction(fn () => $allowance->delete());

        return redirect()
            ->route('admin.allowances.index')
            ->with('success', "Allowance '{$allowance->code}' deleted.");
    }
}
