<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Support\DayBoundary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Income and expenses per month, from the posted ledger.
 *
 * The one aggregate the dashboard needs that {@see LedgerReportService} cannot
 * already answer. A trial balance is one range; a bar chart is twelve, and
 * calling `trialBalance()` once per month would be twelve scans of the ledger
 * to draw one picture. This is a single grouped query instead.
 *
 * It inherits the ledger reports' rules rather than restating them:
 *
 *  - **Posted only**, via `postedLineQuery()`'s shape. A draft is not a fact
 *    about the school's income.
 *  - **Ranged on the entry's own `date`**, never `posted_at` — an entry
 *    backdated into August belongs to August's bar however late it was keyed.
 *  - **Bounds through {@see DayBoundary}**, so the last day of the range does
 *    not silently vanish under SQLite and the `(school_id, date)` index stays
 *    usable.
 *  - **Eloquent before `toBase()`**, so `BelongsToTenant`'s global scope has
 *    attached and qualified its column before the join. Reaching for
 *    `DB::table()` here would chart every school in the database at once.
 *
 * Grouping happens in PHP over `Y-m` keys rather than in SQL date functions:
 * `YEAR()`/`MONTH()` and `strftime()` are spelled differently on MySQL and
 * SQLite, and the tests run on SQLite while production runs on MySQL. The
 * query returns one row per account-month, which is a small result set — a
 * school posts to a few dozen accounts — so the arithmetic is cheap and the
 * SQL stays portable.
 */
final class LedgerSeriesService
{
    /**
     * Monthly income and expense totals across the range.
     *
     * Dense: every month between `from` and `to` comes back, zeroed when
     * nothing was posted. A chart with gaps where a quiet month should be
     * reads as missing data rather than as a quiet month.
     *
     * @return list<array{month: string, label: string, income_centavos: int, expenses_centavos: int}>
     */
    public function monthlyIncomeAndExpenses(
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $months = $this->emptyMonths($from, $to);

        $rows = $this->postedLineQuery()
            ->join(
                'pas_chart_of_accounts',
                'pas_chart_of_accounts.id',
                '=',
                'pas_journal_entry_lines.account_id',
            )
            ->whereIn('pas_chart_of_accounts.type', [
                ChartOfAccount::TYPE_INCOME,
                ChartOfAccount::TYPE_EXPENSE,
            ])
            ->where('pas_journal_entries.date', '>=', DayBoundary::start($from))
            ->where('pas_journal_entries.date', '<=', DayBoundary::end($to))
            ->groupBy(
                'pas_journal_entries.date',
                'pas_chart_of_accounts.type',
                'pas_chart_of_accounts.normal_balance',
            )
            ->select([
                'pas_journal_entries.date as entry_date',
                'pas_chart_of_accounts.type as account_type',
                'pas_chart_of_accounts.normal_balance as normal_balance',
            ])
            ->selectRaw('COALESCE(SUM(pas_journal_entry_lines.debit_centavos), 0) as debits')
            ->selectRaw('COALESCE(SUM(pas_journal_entry_lines.credit_centavos), 0) as credits')
            ->get();

        foreach ($rows as $row) {
            $month = CarbonImmutable::parse((string) $row->entry_date)->format('Y-m');

            if (! isset($months[$month])) {
                continue;
            }

            // Natural signing, so revenue on a credit-normal account reads
            // positive and spending on a debit-normal one does too. The rule
            // is restated here rather than routed through
            // `ChartOfAccount::movementCentavos()` because this query returns
            // aggregates, not models, and hydrating the chart to sign a sum
            // would undo the point of aggregating.
            $raw = (int) $row->debits - (int) $row->credits;
            $natural = $row->normal_balance === ChartOfAccount::BALANCE_DEBIT
                ? $raw
                : -$raw;

            $key = $row->account_type === ChartOfAccount::TYPE_INCOME
                ? 'income_centavos'
                : 'expenses_centavos';

            $months[$month][$key] += $natural;
        }

        return array_values($months);
    }

    /**
     * Every month in the range, zeroed.
     *
     * @return array<string, array{month: string, label: string, income_centavos: int, expenses_centavos: int}>
     */
    private function emptyMonths(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        $cursor = $from->startOfMonth();
        $last = $to->startOfMonth();

        // A range is at most a fiscal year in practice, but a hand-typed one
        // could be anything; the bound stops a decade-wide range from building
        // a series nobody asked for.
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($last) && $guard < 120) {
            $months[$cursor->format('Y-m')] = [
                'month' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'income_centavos' => 0,
                'expenses_centavos' => 0,
            ];

            $cursor = $cursor->addMonth();
            $guard++;
        }

        return $months;
    }

    /**
     * Posted lines joined to their entries, tenant-scoped.
     *
     * Mirrors {@see LedgerReportService::postedLineQuery()} — the same shape
     * for the same reason. `toBase()` comes last so the `BelongsToTenant`
     * global scope has already attached and qualified `school_id`.
     */
    private function postedLineQuery(): QueryBuilder
    {
        return JournalEntryLine::query()
            ->join(
                'pas_journal_entries',
                'pas_journal_entries.id',
                '=',
                'pas_journal_entry_lines.journal_entry_id',
            )
            ->where('pas_journal_entries.status', JournalEntry::STATUS_POSTED)
            ->toBase();
    }
}
