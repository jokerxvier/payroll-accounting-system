<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StatutoryContributionStoreRequest;
use App\Http\Requests\Admin\StatutoryContributionUpdateRequest;
use App\Models\Pas\StatutoryContribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin surface for the unified pas_statutory_contributions table.
 *
 * Read view groups every row by contribution_code and renders the active
 * (effective_to=null) row plus its history. Create view captures a new
 * versioned row; the store path supersedes the prior active row in the same
 * transaction so scopeForDate's invariant holds at all times.
 *
 * Authorization is performed at the top of every method via Gate::authorize
 * against the StatutoryContributionPolicy (super-admin only in v1). The
 * `update` and `void` actions additionally require {@see
 * StatutoryContribution::isEditable()} to be true — past-dated, superseded,
 * or already-voided rows reject mutation even for super-admin.
 */
final class StatutoryContributionController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', StatutoryContribution::class);

        $rows = StatutoryContribution::query()
            ->orderBy('contribution_code')
            ->orderByDesc('effective_from')
            ->get();

        // Group in PHP — the table holds at most a few dozen rows, so a
        // collection groupBy is cheaper and clearer than a SQL window query.
        // The model appends `is_editable` to its array projection (see
        // StatutoryContribution::$appends), so the per-row gate lands on the
        // client automatically without each controller assembling it.
        $grouped = $rows->groupBy('contribution_code')->toArray();

        return Inertia::render('admin/contribution-tables/index', [
            'grouped' => $grouped,
            'recommendedCodes' => StatutoryContribution::CODES,
            'algorithmOptions' => StatutoryContribution::ALGORITHMS,
            // The `update` and `void` policy gates additionally require
            // `isEditable()` per row, but the role check is global — derive
            // it once here so the client can short-circuit rendering icon
            // buttons on rows that would otherwise pass the per-row check.
            'can' => [
                'modify' => Gate::allows('create', StatutoryContribution::class),
            ],
        ]);
    }

    /**
     * Render the create form, optionally prefilled from another row via
     * `?clone={id}`. Cloning copies the algorithm, application_order,
     * applies_to, and rules JSON — but NEVER the contribution_code or the
     * effective_from date. The admin must explicitly pick both: the code
     * because they may want a different one (otherwise they would just edit
     * the source row), and the date because every new version must
     * supersede the prior with a strictly later effective_from.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', StatutoryContribution::class);

        $cloneSource = null;
        $cloneId = $request->query('clone');

        if ($cloneId !== null && is_numeric($cloneId)) {
            $source = StatutoryContribution::query()->find((int) $cloneId);

            if ($source !== null && Gate::allows('view', $source)) {
                $cloneSource = [
                    'id' => $source->id,
                    'contribution_code' => $source->contribution_code,
                    'label' => $source->label,
                    'algorithm' => $source->algorithm,
                    'application_order' => $source->application_order,
                    'applies_to' => $source->applies_to,
                    'rules' => $source->rules,
                    'notes' => $source->notes,
                ];
            }
        }

        return Inertia::render('admin/contribution-tables/create', [
            'recommendedCodes' => StatutoryContribution::CODES,
            'algorithmOptions' => StatutoryContribution::ALGORITHMS,
            'cloneSource' => $cloneSource,
        ]);
    }

    public function store(StatutoryContributionStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', StatutoryContribution::class);

        /** @var array{contribution_code: string, label: string, algorithm: string, application_order: int, applies_to: string, effective_from: string, rules: array<string, mixed>, notes?: ?string} $data */
        $data = $request->validated();

        DB::transaction(function () use ($data): void {
            // Supersede the current open-ended row for this code (if any).
            // FormRequest already rejected effective_from <= max(effective_from)
            // for this code, so the new row's date is strictly after the
            // outgoing row's effective_from — the supersession invariant
            // (outgoing.effective_to == incoming.effective_from) holds.
            $current = StatutoryContribution::query()
                ->where('contribution_code', $data['contribution_code'])
                ->whereNull('effective_to')
                ->first();

            if ($current !== null) {
                $current->update(['effective_to' => $data['effective_from']]);
            }

            StatutoryContribution::create([
                'contribution_code' => $data['contribution_code'],
                'label' => $data['label'],
                'algorithm' => $data['algorithm'],
                'application_order' => $data['application_order'],
                'applies_to' => $data['applies_to'],
                'effective_from' => $data['effective_from'],
                'effective_to' => null,
                'rules' => $data['rules'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return redirect()
            ->route('admin.contribution-tables.index')
            ->with('success', "{$data['contribution_code']} version added.");
    }

    /**
     * Detail view for a single contribution row. Renders the row, every other
     * version of the same code (full supersession history including voided
     * rows — admins need to see retired rates with their void provenance),
     * and a `can` map sourced from policy gates so the UI can hide buttons
     * the user wouldn't be allowed to use.
     */
    public function show(StatutoryContribution $contribution): Response
    {
        Gate::authorize('view', $contribution);

        $contribution->loadMissing('voidedBy');

        $history = StatutoryContribution::query()
            ->where('contribution_code', $contribution->contribution_code)
            ->where('id', '!=', $contribution->id)
            ->with('voidedBy')
            ->orderByDesc('effective_from')
            ->get();

        return Inertia::render('admin/contribution-tables/show', [
            'contribution' => $contribution,
            'history' => $history,
            'can' => [
                'update' => Gate::allows('update', $contribution),
                'void' => Gate::allows('void', $contribution),
            ],
        ]);
    }

    /**
     * Edit form for an editable contribution row. The `update` policy gate
     * combines super-admin role + isEditable(), so a past-dated, superseded,
     * or voided row 403s here without ever rendering the form.
     */
    public function edit(StatutoryContribution $contribution): Response
    {
        Gate::authorize('update', $contribution);

        return Inertia::render('admin/contribution-tables/edit', [
            'contribution' => $contribution,
            'recommendedCodes' => StatutoryContribution::CODES,
            'algorithmOptions' => StatutoryContribution::ALGORITHMS,
        ]);
    }

    /**
     * Persist an in-place update to a future-dated, open-ended row.
     *
     * Authorization is enforced inside the FormRequest's authorize() so a
     * non-editable row 403s before validation runs. `contribution_code` is
     * also pinned by the FormRequest — attempts to rename a code surface as
     * a 422 instead of being silently coerced.
     *
     * Wrapped in a transaction for symmetry with store(); the audit observer
     * fires one `updated` row capturing the diff.
     */
    public function update(
        StatutoryContributionUpdateRequest $request,
        StatutoryContribution $contribution,
    ): RedirectResponse {
        /** @var array{contribution_code: string, label: string, algorithm: string, application_order: int, applies_to: string, effective_from: string, rules: array<string, mixed>, notes?: ?string} $data */
        $data = $request->validated();

        DB::transaction(function () use ($contribution, $data): void {
            $contribution->update([
                'label' => $data['label'],
                'algorithm' => $data['algorithm'],
                'application_order' => $data['application_order'],
                'applies_to' => $data['applies_to'],
                'effective_from' => $data['effective_from'],
                'rules' => $data['rules'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return redirect()
            ->route('admin.contribution-tables.show', $contribution)
            ->with('success', "{$contribution->contribution_code} updated.");
    }

    /**
     * Retire a contribution row. After this returns, the row is hidden from
     * every payroll computation path (registry, current(), forDate scope)
     * and `isEditable()` reads false, so the row cannot be un-voided or
     * further mutated through this surface.
     */
    public function void(Request $request, StatutoryContribution $contribution): RedirectResponse
    {
        Gate::authorize('void', $contribution);

        // The 'auth' middleware in routes/admin.php already guarantees a user
        // is bound to the request, so user() is non-null here. The explicit
        // ?? 0 fallback only exists to satisfy the static analyser; in
        // practice the Gate::authorize call above would have 403'd first if
        // the request were unauthenticated.
        $contribution->void((int) ($request->user()?->id ?? 0));

        return redirect()
            ->route('admin.contribution-tables.show', $contribution)
            ->with('success', 'Contribution voided. It will no longer participate in any payroll computation.');
    }
}
