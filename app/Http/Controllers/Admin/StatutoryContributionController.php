<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StatutoryContributionStoreRequest;
use App\Models\Pas\StatutoryContribution;
use Illuminate\Http\RedirectResponse;
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
 * against the StatutoryContributionPolicy (super-admin only in v1).
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
        $grouped = $rows->groupBy('contribution_code')->toArray();

        return Inertia::render('admin/contribution-tables/index', [
            'grouped' => $grouped,
            'codeOptions' => StatutoryContribution::CODES,
            'algorithmOptions' => StatutoryContribution::ALGORITHMS,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', StatutoryContribution::class);

        return Inertia::render('admin/contribution-tables/create', [
            'codeOptions' => StatutoryContribution::CODES,
            'algorithmOptions' => StatutoryContribution::ALGORITHMS,
        ]);
    }

    public function store(StatutoryContributionStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', StatutoryContribution::class);

        /** @var array{contribution_code: string, label: string, algorithm: string, effective_from: string, rules: array<string, mixed>, notes?: ?string} $data */
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
}
