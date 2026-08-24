<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\TaxRateRequest;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin surface for `pas_tax_rates` — Phase 5 Slice 1.
 *
 * A four-row catalog in practice (12% sales, 12% purchase, exempt,
 * zero-rated), so index() returns everything unpaginated and the index
 * doubles as the listing surface — `show` is not registered, mirroring the
 * allowance and deduction-type controllers.
 */
final class TaxRateController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', TaxRate::class);

        $taxRates = TaxRate::query()
            ->with('account:id,code,name')
            ->orderBy('code')
            ->get();

        return Inertia::render('admin/accounting/tax-rates/index', [
            'taxRates' => $taxRates,
            'can' => [
                'create' => Gate::allows('create', TaxRate::class),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', TaxRate::class);

        return Inertia::render('admin/accounting/tax-rates/create', [
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    public function store(TaxRateRequest $request): RedirectResponse
    {
        Gate::authorize('create', TaxRate::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $taxRate = DB::transaction(fn (): TaxRate => TaxRate::create($data));

        return redirect()
            ->route('admin.tax-rates.index')
            ->with('success', "Tax rate '{$taxRate->code}' created.");
    }

    public function edit(TaxRate $taxRate): Response
    {
        Gate::authorize('update', $taxRate);

        return Inertia::render('admin/accounting/tax-rates/edit', [
            'taxRate' => $taxRate,
            'accountOptions' => $this->accountOptions(),
            'can' => [
                'delete' => Gate::allows('delete', $taxRate),
            ],
        ]);
    }

    public function update(TaxRateRequest $request, TaxRate $taxRate): RedirectResponse
    {
        Gate::authorize('update', $taxRate);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        DB::transaction(fn () => $taxRate->update($data));

        return redirect()
            ->route('admin.tax-rates.index')
            ->with('success', "Tax rate '{$taxRate->code}' updated.");
    }

    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        Gate::authorize('delete', $taxRate);

        DB::transaction(fn () => $taxRate->delete());

        return redirect()
            ->route('admin.tax-rates.index')
            ->with('success', "Tax rate '{$taxRate->code}' deleted.");
    }

    /**
     * Accounts a tax rate may post to.
     *
     * Narrowed to liabilities (output VAT) and assets (input VAT): posting
     * collected VAT to an income account, or creditable VAT to an expense
     * account, is always a mistake, so the picker does not offer them.
     *
     * @return Collection<int, ChartOfAccount>
     */
    private function accountOptions(): Collection
    {
        return ChartOfAccount::query()
            ->active()
            ->whereIn('type', [ChartOfAccount::TYPE_LIABILITY, ChartOfAccount::TYPE_ASSET])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);
    }
}
