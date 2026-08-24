<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Accounting\CloseAccountingPeriodAction;
use App\Actions\Accounting\ReopenAccountingPeriodAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\AccountingPeriodRequest;
use App\Models\Pas\AccountingPeriod;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin surface for `pas_accounting_periods` — Phase 5 Slice 1.
 *
 * Periods are the unit of immutability for the ledger, so this controller
 * exposes four verbs and deliberately not a fifth:
 *
 *   index / create / store / update — ordinary CRUD, minus delete. The policy
 *     refuses deletion outright: Slice 2 attaches journal entries to periods,
 *     and removing one would orphan the ledger's filing system.
 *   close / reopen — the state transitions, each a POST to a Gate-checked
 *     action with its own defensive status guard, mirroring the payroll-run
 *     lifecycle endpoints.
 *
 * Every row ships per-row `can` flags computed from the policy, so the client
 * never has to re-derive authorization or legality of a transition — the same
 * contract `PayrollRunController` uses.
 */
final class AccountingPeriodController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', AccountingPeriod::class);

        $periods = AccountingPeriod::query()
            ->orderByDesc('start_date')
            ->get()
            ->map(fn (AccountingPeriod $period): array => [
                ...$period->only([
                    'id', 'code', 'name', 'fiscal_year', 'status',
                    'closed_at', 'reopened_at',
                ]),
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
                'can' => [
                    'update' => Gate::allows('update', $period),
                    'close' => Gate::allows('close', $period),
                    'reopen' => Gate::allows('reopen', $period),
                ],
            ]);

        return Inertia::render('admin/accounting/periods/index', [
            'periods' => $periods,
            'can' => [
                'create' => Gate::allows('create', AccountingPeriod::class),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', AccountingPeriod::class);

        return Inertia::render('admin/accounting/periods/create');
    }

    public function store(AccountingPeriodRequest $request): RedirectResponse
    {
        Gate::authorize('create', AccountingPeriod::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // Status is never accepted from the client — a period is always born
        // open and moves only through the close/reopen endpoints.
        $data['status'] = AccountingPeriod::STATUS_OPEN;

        $period = DB::transaction(fn (): AccountingPeriod => AccountingPeriod::create($data));

        return redirect()
            ->route('admin.accounting-periods.index')
            ->with('success', "Accounting period '{$period->code}' created.");
    }

    public function edit(AccountingPeriod $accountingPeriod): Response
    {
        Gate::authorize('update', $accountingPeriod);
        $this->assertOpen($accountingPeriod);

        return Inertia::render('admin/accounting/periods/edit', [
            'period' => [
                ...$accountingPeriod->only(['id', 'code', 'name', 'fiscal_year', 'status']),
                'start_date' => $accountingPeriod->start_date->toDateString(),
                'end_date' => $accountingPeriod->end_date->toDateString(),
            ],
        ]);
    }

    public function update(
        AccountingPeriodRequest $request,
        AccountingPeriod $accountingPeriod,
    ): RedirectResponse {
        Gate::authorize('update', $accountingPeriod);
        $this->assertOpen($accountingPeriod);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        DB::transaction(fn () => $accountingPeriod->update($data));

        return redirect()
            ->route('admin.accounting-periods.index')
            ->with('success', "Accounting period '{$accountingPeriod->code}' updated.");
    }

    /**
     * Refuse to reshape a period that is already closed.
     *
     * AccountingPeriodPolicy::update() folds `isOpen()` into its check, but
     * the `Gate::before` short-circuit grants a platform admin every ability
     * and bypasses the state half. Moving a closed period's boundaries would
     * silently change which entries it froze, so the refusal belongs outside
     * authorization.
     */
    private function assertOpen(AccountingPeriod $period): void
    {
        abort_if(
            ! $period->isOpen(),
            403,
            sprintf(
                'Accounting period %s is closed. Reopen it before changing its dates — its boundaries are what every entry inside it was filed against.',
                $period->code,
            ),
        );
    }

    public function close(
        AccountingPeriod $accountingPeriod,
        CloseAccountingPeriodAction $action,
    ): RedirectResponse {
        Gate::authorize('close', $accountingPeriod);

        try {
            $action->execute($accountingPeriod, (int) auth()->id());
        } catch (DomainException $e) {
            return redirect()
                ->route('admin.accounting-periods.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.accounting-periods.index')
            ->with('success', "Accounting period '{$accountingPeriod->code}' closed. No further entries can be posted into it.");
    }

    public function reopen(
        AccountingPeriod $accountingPeriod,
        ReopenAccountingPeriodAction $action,
    ): RedirectResponse {
        Gate::authorize('reopen', $accountingPeriod);

        try {
            $action->execute($accountingPeriod, (int) auth()->id());
        } catch (DomainException $e) {
            return redirect()
                ->route('admin.accounting-periods.index')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.accounting-periods.index')
            ->with('success', "Accounting period '{$accountingPeriod->code}' reopened. This action is recorded in the audit log.");
    }
}
