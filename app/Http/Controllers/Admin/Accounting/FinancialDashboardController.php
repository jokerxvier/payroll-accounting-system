<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Pas\JournalEntry;
use App\Services\Accounting\FiscalYear;
use App\Services\Accounting\Reports\AccountingSummaryService;
use App\Services\Accounting\Reports\LedgerSeriesService;
use App\Support\ReportRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The accounting dashboard — the school's own money, from the posted ledger.
 *
 * Deliberately separate from `/dashboard`, which is payroll and HR and is
 * ungated for every authenticated user. These figures are the school's profit
 * and its bank balance, so the page authorises exactly as the ledger reports
 * do: `viewAny` on `JournalEntry`, which is `AccountingRoles::VIEW`. Reading a
 * dashboard is reading the books, and gating it any other way would let the
 * two drift on who may see them.
 *
 * **Every figure here comes from posted journal entries.** Not from invoices —
 * an invoice is an operational record and a posted entry is the school's
 * position. The invoice dashboard answers the operational question separately,
 * and the two are allowed to differ: a draft invoice is real work and is not
 * yet revenue.
 */
final class FinancialDashboardController extends Controller
{
    public function __construct(
        private readonly AccountingSummaryService $summary,
        private readonly LedgerSeriesService $series,
        private readonly FiscalYear $fiscalYear,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', JournalEntry::class);

        $preset = ReportRange::preset($request->query('preset'));

        [$from, $to] = ReportRange::resolve(
            $preset,
            $request->query('from'),
            $request->query('to'),
            $this->fiscalYear,
        );

        return Inertia::render('admin/accounting/reports/accounting-dashboard', [
            'filters' => [
                'preset' => $preset,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'summary' => $this->summary->forRange($from, $to)->toArray(),
            'monthlySeries' => $this->series->monthlyIncomeAndExpenses($from, $to),
        ]);
    }
}
