<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Pas\JournalEntry;
use App\Services\Accounting\FiscalYear;
use App\Services\Accounting\Reports\AccountingSummaryService;
use App\Services\Accounting\Reports\LedgerSeriesService;
use App\Support\DayBoundary;
use Carbon\CarbonImmutable;
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
    /** Ranges the page offers without asking anyone to pick dates. */
    private const PRESETS = ['month', 'quarter', 'year', 'custom'];

    public function __construct(
        private readonly AccountingSummaryService $summary,
        private readonly LedgerSeriesService $series,
        private readonly FiscalYear $fiscalYear,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', JournalEntry::class);

        $preset = (string) $request->query('preset', 'year');
        $preset = in_array($preset, self::PRESETS, true) ? $preset : 'year';

        [$from, $to] = $this->resolveRange($request, $preset);

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

    /**
     * The dates to report on.
     *
     * Defaults to the school's current fiscal year, which is what the spec
     * asks for and what a head teacher means by "this year" — a school whose
     * year runs June to March would be shown ten months of it by a calendar
     * default.
     *
     * An inverted custom range is swapped rather than refused, matching
     * `LedgerReportController::resolveDateRange()`: someone who picked the
     * dates the wrong way round meant the range between them.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(Request $request, string $preset): array
    {
        // Manila, not UTC. `config/app.php` is UTC while the schools are
        // UTC+8, so for the eight hours after midnight local, `now()` is still
        // yesterday — and on the first of the month that makes "This month"
        // resolve to the whole of the previous one. Same correction
        // `GenerateRecurringInvoices` makes, for the same reason: a date the
        // operator reads off a wall clock has to mean their date.
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();

        if ($preset === 'month') {
            return [$today->startOfMonth(), $today->endOfMonth()->startOfDay()];
        }

        if ($preset === 'quarter') {
            return [$today->firstOfQuarter(), $today->lastOfQuarter()];
        }

        if ($preset === 'year') {
            return $this->fiscalYear->currentRange($today);
        }

        // Custom. Through DayBoundary::parse, which answers null for anything
        // it cannot read rather than throwing — a filter that declines to
        // narrow beats an error page.
        $from = DayBoundary::parse($request->query('from'));
        $to = DayBoundary::parse($request->query('to'));

        if ($from === null || $to === null) {
            return $this->fiscalYear->currentRange($today);
        }

        $from = $from->startOfDay();
        $to = $to->startOfDay();

        return $to->lessThan($from) ? [$to, $from] : [$from, $to];
    }
}
