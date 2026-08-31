<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Pas\Invoice;
use App\Services\Accounting\FiscalYear;
use App\Services\Accounting\Reports\ReceivablesService;
use App\Support\DayBoundary;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The invoice dashboard — what has been billed, collected, and is still owed.
 *
 * The operational counterpart to the accounting dashboard, and gated
 * differently on purpose. That one reads the ledger and answers to
 * `JournalEntry`; this one reads documents, so it authorises on `Invoice` —
 * an officer who chases payments can see who owes what without being handed
 * the school's profit.
 *
 * **The two are allowed to disagree.** A draft invoice is real work an officer
 * is chasing and is not yet revenue; approving it is what moves it to the
 * ledger. Reading a figure here as the school's position is the mistake this
 * split exists to prevent.
 */
final class ReceivablesDashboardController extends Controller
{
    private const PRESETS = ['month', 'quarter', 'year', 'custom'];

    public function __construct(
        private readonly ReceivablesService $receivables,
        private readonly FiscalYear $fiscalYear,
    ) {}

    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Invoice::class);

        $preset = (string) $request->query('preset', 'year');
        $preset = in_array($preset, self::PRESETS, true) ? $preset : 'year';

        // Manila, not UTC. The app runs UTC while the schools are UTC+8, so
        // for eight hours after local midnight `now()` is still yesterday —
        // and on the first of a month that resolves "This month" to the whole
        // of the previous one. It also decides what counts as overdue, where
        // being a day out is the difference between a chased invoice and a
        // clean one.
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();

        [$from, $to] = $this->resolveRange($request, $preset, $today);

        return Inertia::render('admin/accounting/reports/invoice-dashboard', [
            'filters' => [
                'preset' => $preset,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            // Ageing is always as at TODAY, never the end of the range. "How
            // overdue is this" is a question about now; asking it as at a past
            // date would report debts as current that have since gone bad.
            'summary' => $this->receivables->forRange($from, $to, $today)->toArray(),
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(
        Request $request,
        string $preset,
        CarbonImmutable $today,
    ): array {
        if ($preset === 'month') {
            return [$today->startOfMonth(), $today->endOfMonth()->startOfDay()];
        }

        if ($preset === 'quarter') {
            return [$today->firstOfQuarter(), $today->lastOfQuarter()];
        }

        if ($preset === 'year') {
            return $this->fiscalYear->currentRange($today);
        }

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
