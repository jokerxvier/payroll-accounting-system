<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\ChartOfAccountStoreRequest;
use App\Http\Requests\Admin\Accounting\ChartOfAccountUpdateRequest;
use App\Models\Pas\ChartOfAccount;
use App\Policies\Pas\ChartOfAccountPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin surface for `pas_chart_of_accounts` — Phase 5 Slice 1.
 *
 * Authorization is per-action via Gate::authorize against
 * {@see ChartOfAccountPolicy}.
 *
 * The chart is small (the seeded default is ~35 rows, and a large school
 * might reach a few hundred) and is read as a whole document rather than
 * scanned page by page, so index() returns the full list ordered by code
 * without pagination — same call as the allowance and deduction-type
 * catalogs.
 */
final class ChartOfAccountController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', ChartOfAccount::class);

        $accounts = ChartOfAccount::query()
            ->orderBy('code')
            ->get();

        return Inertia::render('admin/accounting/chart-of-accounts/index', [
            'accounts' => $accounts,
            // The client renders per-row actions from these rather than
            // re-deriving the policy in TypeScript.
            'can' => [
                'create' => Gate::allows('create', ChartOfAccount::class),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', ChartOfAccount::class);

        return Inertia::render('admin/accounting/chart-of-accounts/create', [
            'parentOptions' => $this->parentOptions(),
        ]);
    }

    public function store(ChartOfAccountStoreRequest $request): RedirectResponse
    {
        Gate::authorize('create', ChartOfAccount::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $account = DB::transaction(fn (): ChartOfAccount => ChartOfAccount::create($data));

        return redirect()
            ->route('admin.chart-of-accounts.index')
            ->with('success', "Account {$account->code} — {$account->name} created.");
    }

    public function edit(ChartOfAccount $chartOfAccount): Response
    {
        Gate::authorize('update', $chartOfAccount);

        return Inertia::render('admin/accounting/chart-of-accounts/edit', [
            'account' => $chartOfAccount,
            // Exclude self so the form cannot offer an account as its own
            // parent; the FormRequest rejects it too, but the UI should not
            // present the option in the first place.
            'parentOptions' => $this->parentOptions(exceptId: (int) $chartOfAccount->getKey()),
            'can' => [
                'delete' => Gate::allows('delete', $chartOfAccount),
            ],
        ]);
    }

    public function update(
        ChartOfAccountUpdateRequest $request,
        ChartOfAccount $chartOfAccount,
    ): RedirectResponse {
        Gate::authorize('update', $chartOfAccount);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        DB::transaction(fn () => $chartOfAccount->update($data));

        return redirect()
            ->route('admin.chart-of-accounts.index')
            ->with('success', "Account {$chartOfAccount->code} updated.");
    }

    /**
     * Delete an account.
     *
     * Two soft-blocks before the delete, both surfacing a flash message
     * rather than letting the database raise a foreign-key error:
     *
     *   - System accounts are refused by the policy (they are `is_locked`),
     *     so Gate::authorize below already stops them.
     *   - An account with children would trip the `restrictOnDelete` self-FK.
     *
     * Slice 2 adds a third: an account with posted journal lines can never be
     * deleted, only deactivated, because the ledger's history refers to it.
     */
    public function destroy(ChartOfAccount $chartOfAccount): RedirectResponse
    {
        Gate::authorize('delete', $chartOfAccount);

        if ($chartOfAccount->children()->exists()) {
            return redirect()
                ->route('admin.chart-of-accounts.index')
                ->with('error', 'Cannot delete an account that has sub-accounts. Reassign or delete those first, or deactivate this account instead.');
        }

        if ($chartOfAccount->taxRates()->exists()) {
            return redirect()
                ->route('admin.chart-of-accounts.index')
                ->with('error', 'Cannot delete an account that a tax rate posts to. Repoint the tax rate first, or deactivate this account instead.');
        }

        DB::transaction(fn () => $chartOfAccount->delete());

        return redirect()
            ->route('admin.chart-of-accounts.index')
            ->with('success', "Account {$chartOfAccount->code} deleted.");
    }

    /**
     * Accounts selectable as a parent, as a lean id/code/name list.
     *
     * @return Collection<int, ChartOfAccount>
     */
    private function parentOptions(?int $exceptId = null): Collection
    {
        return ChartOfAccount::query()
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);
    }
}
