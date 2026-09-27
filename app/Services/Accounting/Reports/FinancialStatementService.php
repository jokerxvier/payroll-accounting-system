<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Models\Pas\ChartOfAccount;
use App\Services\Accounting\FiscalYear;
use Carbon\CarbonImmutable;

/**
 * The Income Statement and the Balance Sheet (Phase 5 Slice 8b).
 *
 * Classifies and subtotals what {@see LedgerReportService} returns; it adds
 * no way of reading the ledger of its own. Posted entries only, ranged on the
 * entry's date, scoped to the current school — all inherited, none restated.
 *
 * **Nothing here writes.** The Balance Sheet's earnings lines are computed on
 * every read and posted nowhere.
 */
final class FinancialStatementService
{
    /** @var list<string> in print order */
    private const BALANCE_SHEET_SECTIONS = [
        StatementSectionMap::CURRENT_ASSETS,
        StatementSectionMap::NON_CURRENT_ASSETS,
        StatementSectionMap::CURRENT_LIABILITIES,
        StatementSectionMap::NON_CURRENT_LIABILITIES,
        StatementSectionMap::EQUITY,
    ];

    public function __construct(
        private readonly LedgerReportService $ledger,
        private readonly FiscalYear $fiscalYear,
    ) {}

    /**
     * @param  bool  $includeZero  Print active income and expense accounts
     *                             that did not move in any column.
     */
    public function incomeStatement(StatementColumnSet $columns, bool $includeZero = false): IncomeStatement
    {
        $sums = $this->ledger->columnSums($columns->columns);
        $columnCount = count($columns->columns);

        $accounts = ChartOfAccount::query()
            ->whereIn('type', [ChartOfAccount::TYPE_INCOME, ChartOfAccount::TYPE_EXPENSE])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'normal_balance', 'subtype', 'cash_flow_category', 'is_active']);

        /** @var array<string, list<StatementLine>> $sections */
        $sections = [
            StatementSectionMap::REVENUE => [],
            StatementSectionMap::OPERATING_EXPENSES => [],
            StatementSectionMap::OTHER_INCOME => [],
            StatementSectionMap::OTHER_EXPENSES => [],
        ];

        foreach ($accounts as $account) {
            $amounts = [];

            for ($index = 0; $index < $columnCount; $index++) {
                $sum = $sums[(int) $account->getKey()][$index] ?? ['debit' => 0, 'credit' => 0];

                $amounts[] = $account->movementCentavos($sum['debit'], $sum['credit']);
            }

            [$section, $unclassified] = StatementSectionMap::sectionFor(
                $account->type,
                $account->subtype,
                $account->cash_flow_category,
            );

            $line = new StatementLine(
                kind: StatementLine::KIND_ACCOUNT,
                key: 'account:'.$account->getKey(),
                section: $section,
                name: $account->name,
                amountsCentavos: $amounts,
                accountId: (int) $account->getKey(),
                code: $account->code,
                isActive: (bool) $account->is_active,
                isUnclassified: $unclassified,
            );

            // An account that moved is always printed, live or not. One that
            // did not is printed only on request, and never once retired.
            if ($line->isZero() && ! ($includeZero && $line->isActive)) {
                continue;
            }

            $sections[$section][] = $columns->hasVariance
                ? $line->compared($account->type === ChartOfAccount::TYPE_INCOME)
                : $line;
        }

        $totals = array_map(
            fn (array $lines): array => self::sum($lines, $columnCount),
            $sections,
        );

        $operatingIncome = self::subtract(
            $totals[StatementSectionMap::REVENUE],
            $totals[StatementSectionMap::OPERATING_EXPENSES],
        );
        $netIncome = self::subtract(
            self::add($operatingIncome, $totals[StatementSectionMap::OTHER_INCOME]),
            $totals[StatementSectionMap::OTHER_EXPENSES],
        );

        $lines = [];

        foreach ([StatementSectionMap::REVENUE, StatementSectionMap::OPERATING_EXPENSES] as $section) {
            array_push($lines, ...$this->section(
                $section,
                $sections[$section],
                $totals[$section],
                $columns->hasVariance,
                $section === StatementSectionMap::REVENUE,
            ));
        }

        $lines[] = $this->figure('operating_income', 'Operating income', $operatingIncome, $columns->hasVariance);

        // The two "other" sections are printed only when something is in
        // them. Most schools have neither, and an empty heading over a zero
        // subtotal is two lines of nothing.
        foreach ([StatementSectionMap::OTHER_INCOME, StatementSectionMap::OTHER_EXPENSES] as $section) {
            if ($sections[$section] === []) {
                continue;
            }

            array_push($lines, ...$this->section(
                $section,
                $sections[$section],
                $totals[$section],
                $columns->hasVariance,
                $section === StatementSectionMap::OTHER_INCOME,
            ));
        }

        $lines[] = $this->figure('net_income', 'Net income', $netIncome, $columns->hasVariance);

        return new IncomeStatement($columns, $lines, $netIncome);
    }

    /**
     * @param  bool  $includeZero  Print accounts whose balance is nil.
     * @param  CarbonImmutable|null  $compareAsAt  A second date to set beside
     *                                             the first. Each date is a
     *                                             whole Balance Sheet of its
     *                                             own — its own fiscal year,
     *                                             its own earnings, its own
     *                                             proof that it balances.
     */
    public function balanceSheet(
        CarbonImmutable $asAt,
        bool $includeZero = false,
        ?CarbonImmutable $compareAsAt = null,
    ): BalanceSheet {
        $positions = [$this->position($asAt, $includeZero)];

        if ($compareAsAt !== null) {
            $positions[] = $this->position($compareAsAt, $includeZero);
        }

        $columnCount = count($positions);
        $hasVariance = $columnCount === 2;
        $sections = [];

        foreach (self::BALANCE_SHEET_SECTIONS as $section) {
            $sections[$section] = $this->mergeSection(
                array_map(static fn (array $position): array => $position['sections'][$section], $positions),
                $hasVariance,
            );
        }

        $totals = array_map(
            fn (array $lines): array => self::sum($lines, $columnCount),
            $sections,
        );

        $assets = self::add($totals[StatementSectionMap::CURRENT_ASSETS], $totals[StatementSectionMap::NON_CURRENT_ASSETS]);
        $liabilities = self::add($totals[StatementSectionMap::CURRENT_LIABILITIES], $totals[StatementSectionMap::NON_CURRENT_LIABILITIES]);
        $equity = $totals[StatementSectionMap::EQUITY];

        $lines = [];

        foreach ([StatementSectionMap::CURRENT_ASSETS, StatementSectionMap::NON_CURRENT_ASSETS] as $section) {
            if ($sections[$section] !== []) {
                array_push($lines, ...$this->section($section, $sections[$section], $totals[$section], $hasVariance, null));
            }
        }

        $lines[] = $this->figure('total_assets', 'Total assets', $assets, $hasVariance, null);

        foreach ([StatementSectionMap::CURRENT_LIABILITIES, StatementSectionMap::NON_CURRENT_LIABILITIES] as $section) {
            if ($sections[$section] !== []) {
                array_push($lines, ...$this->section($section, $sections[$section], $totals[$section], $hasVariance, null));
            }
        }

        $lines[] = $this->figure('total_liabilities', 'Total liabilities', $liabilities, $hasVariance, null);

        array_push($lines, ...$this->section(
            StatementSectionMap::EQUITY,
            $sections[StatementSectionMap::EQUITY],
            $equity,
            $hasVariance,
            null,
        ));

        $lines[] = $this->figure(
            'total_liabilities_and_equity',
            'Total liabilities and equity',
            self::add($liabilities, $equity),
            $hasVariance,
            null,
        );

        $columns = [];

        foreach ($positions as $index => $position) {
            $columns[] = new BalanceSheetColumn(
                asAt: $position['asAt'],
                fiscalYearStart: $position['yearStart'],
                assetsCentavos: $assets[$index],
                liabilitiesCentavos: $liabilities[$index],
                equityCentavos: $equity[$index],
            );
        }

        return new BalanceSheet($columns, $lines);
    }

    /**
     * The date a named comparison means, for a Balance Sheet as at `$asAt`.
     *
     * "The end of last year" is the day before the fiscal year `$asAt` falls
     * in began, which is a question about the school's periods and not the
     * calendar — hence here, beside the fiscal year, and not in the page.
     */
    public function comparisonDate(string $mode, CarbonImmutable $asAt, ?CarbonImmutable $chosen): ?CarbonImmutable
    {
        $asAt = $asAt->startOfDay();

        return match ($mode) {
            BalanceSheet::COMPARE_PREVIOUS_MONTH_END => $asAt->startOfMonth()->subDay()->startOfDay(),
            BalanceSheet::COMPARE_PREVIOUS_YEAR_END => $this->fiscalYear->currentRange($asAt)[0]->subDay()->startOfDay(),
            BalanceSheet::COMPARE_CUSTOM => $chosen?->startOfDay(),
            default => null,
        };
    }

    /**
     * One date's Balance Sheet, as sections of single-figure lines.
     *
     * @return array{
     *     asAt: CarbonImmutable,
     *     yearStart: CarbonImmutable,
     *     sections: array<string, list<StatementLine>>
     * }
     */
    private function position(CarbonImmutable $asAt, bool $includeZero): array
    {
        $asAt = $asAt->startOfDay();
        $yearStart = $this->fiscalYear->currentRange($asAt)[0];

        // One read supplies the balances and both earnings figures: ranged
        // from the start of the fiscal year, the opening columns are
        // everything before it and the period columns are this year.
        // `includeEmpty` so Retained Earnings arrives even at nil — the
        // zero rows are dropped below instead.
        $rows = $this->ledger->trialBalance($yearStart, $asAt, includeEmpty: true)->rows;

        $priorEarnings = 0;
        $currentEarnings = 0;

        /** @var array<string, list<StatementLine>> $sections */
        $sections = [
            StatementSectionMap::CURRENT_ASSETS => [],
            StatementSectionMap::NON_CURRENT_ASSETS => [],
            StatementSectionMap::CURRENT_LIABILITIES => [],
            StatementSectionMap::NON_CURRENT_LIABILITIES => [],
            StatementSectionMap::EQUITY => [],
        ];

        $ledgerDrill = [
            'report' => StatementLine::DRILL_GENERAL_LEDGER,
            'from' => $yearStart->toDateString(),
            'to' => $asAt->toDateString(),
        ];

        $retainedEarnings = null;
        $retainedEarningsPosition = null;

        foreach ($rows as $row) {
            if ($row->type === ChartOfAccount::TYPE_INCOME) {
                $priorEarnings += $row->openingNaturalCentavos();
                $currentEarnings += $row->periodNaturalCentavos();

                continue;
            }

            if ($row->type === ChartOfAccount::TYPE_EXPENSE) {
                $priorEarnings -= $row->openingNaturalCentavos();
                $currentEarnings -= $row->periodNaturalCentavos();

                continue;
            }

            // Held back: it is printed with the earlier years' profit folded
            // in, and that figure is not known until the loop has finished.
            if ($row->systemCode === ChartOfAccount::SYSTEM_RETAINED_EARNINGS && $retainedEarnings === null) {
                $retainedEarnings = $row;
                // Remembered so it is printed where its code puts it, among
                // the other equity accounts, rather than after them.
                $retainedEarningsPosition = count($sections[StatementSectionMap::EQUITY]);

                continue;
            }

            $balance = $row->closingNaturalCentavos();

            if ($balance === 0 && ! ($includeZero && $row->isActive)) {
                continue;
            }

            [$section, $unclassified] = StatementSectionMap::sectionFor(
                $row->type,
                $row->subtype,
                $row->cashFlowCategory,
            );

            $sections[$section][] = new StatementLine(
                kind: StatementLine::KIND_ACCOUNT,
                key: 'account:'.$row->accountId,
                section: $section,
                name: $row->name,
                amountsCentavos: [$balance],
                accountId: $row->accountId,
                code: $row->code,
                isActive: $row->isActive,
                isUnclassified: $unclassified,
                drill: $ledgerDrill,
            );
        }

        $earnings = $this->earningsLines($retainedEarnings, $priorEarnings, $currentEarnings, $yearStart, $asAt, $ledgerDrill);
        $currentYear = array_pop($earnings);

        array_splice(
            $sections[StatementSectionMap::EQUITY],
            $retainedEarningsPosition ?? count($sections[StatementSectionMap::EQUITY]),
            0,
            $earnings,
        );
        $sections[StatementSectionMap::EQUITY][] = $currentYear;

        return ['asAt' => $asAt, 'yearStart' => $yearStart, 'sections' => $sections];
    }

    /**
     * One section across every date, a line for each account that appears
     * under any of them.
     *
     * An account missing from one date held nothing on it — it was nil and
     * left out, or did not exist yet — so its figure there is nil, and it is
     * still printed for the date it did hold something.
     *
     * Lines are put back in the order a single date prints them: by code,
     * Retained Earnings followed by what it is made of, Current-year
     * earnings last.
     *
     * @param  list<list<StatementLine>>  $perColumn
     * @return list<StatementLine>
     */
    private function mergeSection(array $perColumn, bool $hasVariance): array
    {
        $columnCount = count($perColumn);

        /** @var array<string, StatementLine> $first the line as first seen */
        $first = [];
        /** @var array<string, list<int>> $amounts */
        $amounts = [];

        foreach ($perColumn as $index => $lines) {
            foreach ($lines as $line) {
                $first[$line->key] ??= $line;
                $amounts[$line->key] ??= array_fill(0, $columnCount, 0);
                $amounts[$line->key][$index] = $line->amountsCentavos[0] ?? 0;
            }
        }

        $retainedEarningsCode = ($first['retained_earnings'] ?? null)?->code;

        $order = static function (StatementLine $line) use ($retainedEarningsCode): array {
            return match ($line->key) {
                'current_year_earnings' => [2, '', 0],
                'retained_earnings' => [$line->code === null ? 1 : 0, (string) $line->code, 0],
                'retained_earnings:ledger' => [$retainedEarningsCode === null ? 1 : 0, (string) $retainedEarningsCode, 1],
                'retained_earnings:unclosed' => [$retainedEarningsCode === null ? 1 : 0, (string) $retainedEarningsCode, 2],
                default => [0, (string) $line->code, 0],
            };
        };

        $merged = array_values($first);

        usort($merged, static fn (StatementLine $a, StatementLine $b): int => $order($a) <=> $order($b));

        return array_map(
            static function (StatementLine $line) use ($amounts, $hasVariance): StatementLine {
                $line = $line->withAmounts($amounts[$line->key]);

                return $hasVariance ? $line->compared(null) : $line;
            },
            $merged,
        );
    }

    /**
     * Retained Earnings and Current-year earnings, as the equity section
     * prints them.
     *
     * Retained Earnings is the account's own balance plus every earlier
     * year's profit still sitting in income and expense accounts. When that
     * second part is not nil the two are itemised beneath it, because they
     * are read in different places: the balance in the account's ledger, the
     * unclosed profit on the Income Statement. A single figure linking to the
     * ledger would send the reader to a page showing a different number.
     *
     * Should a year-end close ever be built, its closing entries empty the
     * income and expense accounts, the unclosed part becomes nil, and this
     * prints the account balance alone without being changed.
     *
     * @param  array{report: string, from: string, to: string}  $ledgerDrill
     * @return non-empty-list<StatementLine> Retained Earnings and what it is
     *                                       made of, then Current-year
     *                                       earnings last
     */
    private function earningsLines(
        ?TrialBalanceRow $retainedEarnings,
        int $priorEarnings,
        int $currentEarnings,
        CarbonImmutable $yearStart,
        CarbonImmutable $asAt,
        array $ledgerDrill,
    ): array {
        $section = StatementSectionMap::EQUITY;
        $ledgerBalance = $retainedEarnings?->closingNaturalCentavos() ?? 0;

        $lines = [
            new StatementLine(
                kind: $retainedEarnings === null ? StatementLine::KIND_COMPUTED : StatementLine::KIND_ACCOUNT,
                key: 'retained_earnings',
                section: $section,
                name: $retainedEarnings === null ? 'Retained earnings' : $retainedEarnings->name,
                amountsCentavos: [$ledgerBalance + $priorEarnings],
                accountId: $retainedEarnings?->accountId,
                code: $retainedEarnings?->code,
                isActive: $retainedEarnings === null || $retainedEarnings->isActive,
                // Linked only when the figure is the account's and nothing
                // else; otherwise the two parts below carry the links.
                drill: $retainedEarnings !== null && $priorEarnings === 0 ? $ledgerDrill : null,
            ),
        ];

        if ($priorEarnings !== 0) {
            if ($retainedEarnings !== null) {
                $lines[] = new StatementLine(
                    kind: StatementLine::KIND_DETAIL,
                    key: 'retained_earnings:ledger',
                    section: $section,
                    name: 'Ledger balance',
                    amountsCentavos: [$ledgerBalance],
                    accountId: $retainedEarnings->accountId,
                    drill: $ledgerDrill,
                );
            }

            $priorTo = $yearStart->subDay();
            $priorFrom = $this->ledger->firstPostedDate() ?? $priorTo;

            $lines[] = new StatementLine(
                kind: StatementLine::KIND_DETAIL,
                key: 'retained_earnings:unclosed',
                section: $section,
                name: 'Prior-year profit not yet closed',
                amountsCentavos: [$priorEarnings],
                drill: [
                    'report' => StatementLine::DRILL_INCOME_STATEMENT,
                    'from' => ($priorFrom->greaterThan($priorTo) ? $priorTo : $priorFrom)->toDateString(),
                    'to' => $priorTo->toDateString(),
                ],
            );
        }

        $lines[] = new StatementLine(
            kind: StatementLine::KIND_COMPUTED,
            key: 'current_year_earnings',
            section: $section,
            name: 'Current-year earnings',
            amountsCentavos: [$currentEarnings],
            drill: [
                'report' => StatementLine::DRILL_INCOME_STATEMENT,
                'from' => $yearStart->toDateString(),
                'to' => $asAt->toDateString(),
            ],
        );

        return $lines;
    }

    /**
     * A section as printed: its heading, its lines, its subtotal.
     *
     * @param  list<StatementLine>  $lines
     * @param  list<int>  $total
     * @return list<StatementLine>
     */
    private function section(
        string $section,
        array $lines,
        array $total,
        bool $hasVariance,
        ?bool $moreIsBetter,
    ): array {
        $label = StatementSectionMap::LABELS[$section];

        $subtotal = new StatementLine(
            kind: StatementLine::KIND_SUBTOTAL,
            key: "subtotal:{$section}",
            section: $section,
            name: 'Total '.lcfirst($label),
            amountsCentavos: $total,
        );

        return [
            StatementLine::heading($section, $label),
            ...$lines,
            $hasVariance ? $subtotal->compared($moreIsBetter) : $subtotal,
        ];
    }

    /**
     * A figure that stands outside any one section: operating income, net
     * income, total assets.
     *
     * @param  list<int>  $amounts
     */
    private function figure(
        string $key,
        string $name,
        array $amounts,
        bool $hasVariance,
        ?bool $moreIsBetter = true,
    ): StatementLine {
        $line = new StatementLine(
            kind: StatementLine::KIND_TOTAL,
            key: $key,
            section: $key,
            name: $name,
            amountsCentavos: $amounts,
        );

        return $hasVariance ? $line->compared($moreIsBetter) : $line;
    }

    /**
     * Column totals over a section's lines. Detail lines are left out: they
     * itemise a line that is already counted.
     *
     * @param  list<StatementLine>  $lines
     * @return list<int>
     */
    private static function sum(array $lines, int $columnCount): array
    {
        $total = array_fill(0, $columnCount, 0);

        foreach ($lines as $line) {
            if ($line->kind === StatementLine::KIND_DETAIL) {
                continue;
            }

            foreach ($line->amountsCentavos as $index => $amount) {
                $total[$index] += $amount;
            }
        }

        return array_values($total);
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     * @return list<int>
     */
    private static function add(array $a, array $b): array
    {
        return array_map(static fn (int $x, int $y): int => $x + $y, $a, $b);
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     * @return list<int>
     */
    private static function subtract(array $a, array $b): array
    {
        return array_map(static fn (int $x, int $y): int => $x - $y, $a, $b);
    }
}
