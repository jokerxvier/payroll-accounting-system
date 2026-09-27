<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use Carbon\CarbonImmutable;

/**
 * Turns "this range, compared this way" into the columns to report.
 *
 * Pure date arithmetic: no request, no database. Everything that can go wrong
 * with a comparison is a calendar problem — months of different lengths, a
 * leap day, a range that is not whole months — and those are much easier to
 * pin down in a class that does nothing else.
 */
final class StatementColumnResolver
{
    /**
     * The most month columns a statement will print. Twelve is a fiscal year,
     * and with a total it is as many as an A4 landscape page holds legibly.
     */
    public const MAX_MONTH_COLUMNS = 12;

    public function resolve(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $mode = StatementColumnSet::MODE_NONE,
        ?CarbonImmutable $compareFrom = null,
        ?CarbonImmutable $compareTo = null,
        ?CarbonImmutable $booksOpenedOn = null,
    ): StatementColumnSet {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        $primary = new StatementColumn(
            'primary',
            self::rangeLabel($from, $to),
            $from,
            $to,
            StatementColumn::ROLE_PRIMARY,
        );

        return match ($mode) {
            StatementColumnSet::MODE_PREVIOUS_PERIOD => $this->compared(
                $mode, $primary, ...self::previousPeriod($from, $to), booksOpenedOn: $booksOpenedOn,
            ),
            StatementColumnSet::MODE_PREVIOUS_YEAR => $this->compared(
                $mode, $primary, ...self::previousYear($from, $to), booksOpenedOn: $booksOpenedOn,
            ),
            StatementColumnSet::MODE_CUSTOM => $this->custom($primary, $compareFrom, $compareTo, $booksOpenedOn),
            StatementColumnSet::MODE_MONTHLY => $this->monthly($primary),
            default => new StatementColumnSet(StatementColumnSet::MODE_NONE, $primary, [$primary], false),
        };
    }

    /**
     * The range immediately before, of the same length.
     *
     * "The same length" means the same number of calendar months when the
     * range is whole months, and the same number of days otherwise. Counting
     * days for February would compare it against 4–31 January, which is
     * nobody's idea of the month before.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function previousPeriod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $previousTo = $from->subDay();

        if (self::isWholeMonths($from, $to)) {
            $months = ($to->year - $from->year) * 12 + $to->month - $from->month + 1;

            return [$from->subMonthsNoOverflow($months), $previousTo];
        }

        $days = (int) $from->diffInDays($to) + 1;

        return [$previousTo->subDays($days - 1), $previousTo];
    }

    /**
     * The same dates one year earlier.
     *
     * A range ending on a month end ends on a month end last year too, so
     * February compares against the whole of the February before whether or
     * not either was a leap year.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function previousYear(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $previousTo = $to->subYearNoOverflow();

        if (self::isMonthEnd($to)) {
            $previousTo = $previousTo->endOfMonth()->startOfDay();
        }

        return [$from->subYearNoOverflow(), $previousTo];
    }

    private function custom(
        StatementColumn $primary,
        ?CarbonImmutable $compareFrom,
        ?CarbonImmutable $compareTo,
        ?CarbonImmutable $booksOpenedOn,
    ): StatementColumnSet {
        if ($compareFrom === null || $compareTo === null) {
            return new StatementColumnSet(StatementColumnSet::MODE_NONE, $primary, [$primary], false, [
                'Choose both comparison dates to compare against a custom range.',
            ]);
        }

        $compareFrom = $compareFrom->startOfDay();
        $compareTo = $compareTo->startOfDay();

        // Swapped rather than refused, as every other range in the reports is.
        if ($compareTo->lessThan($compareFrom)) {
            [$compareFrom, $compareTo] = [$compareTo, $compareFrom];
        }

        $notices = [];

        if ($compareFrom->lessThanOrEqualTo($primary->to) && $compareTo->greaterThanOrEqualTo($primary->from)) {
            $notices[] = 'The comparison range overlaps the reported range, so some entries are counted in both columns.';
        }

        return $this->compared(
            StatementColumnSet::MODE_CUSTOM,
            $primary,
            $compareFrom,
            $compareTo,
            $notices,
            $booksOpenedOn,
        );
    }

    /**
     * @param  list<string>  $notices
     */
    private function compared(
        string $mode,
        StatementColumn $primary,
        CarbonImmutable $compareFrom,
        CarbonImmutable $compareTo,
        array $notices = [],
        ?CarbonImmutable $booksOpenedOn = null,
    ): StatementColumnSet {
        // "May be incomplete", not "is empty": this class sees dates, not
        // entries, and a school can post entries dated before its cutover.
        if ($booksOpenedOn !== null && $compareTo->lessThan($booksOpenedOn->startOfDay())) {
            $notices[] = sprintf(
                'The comparison range ends before the books were opened on %s, so its figures may be incomplete.',
                $booksOpenedOn->format('j M Y'),
            );
        }

        $comparison = new StatementColumn(
            'comparison',
            self::rangeLabel($compareFrom, $compareTo),
            $compareFrom,
            $compareTo,
            StatementColumn::ROLE_COMPARISON,
        );

        return new StatementColumnSet($mode, $primary, [$primary, $comparison], true, $notices);
    }

    /**
     * One column per calendar month the range touches, then the range itself
     * as the total.
     *
     * A range too long for the page falls back to a single column with a
     * notice. Printing the first twelve months would put a total under them
     * that includes months the reader cannot see.
     */
    private function monthly(StatementColumn $primary): StatementColumnSet
    {
        $columns = [];
        $cursor = $primary->from->startOfMonth();

        while ($cursor->lessThanOrEqualTo($primary->to)) {
            $monthEnd = $cursor->endOfMonth()->startOfDay();
            $start = $cursor->lessThan($primary->from) ? $primary->from : $cursor;
            $end = $monthEnd->greaterThan($primary->to) ? $primary->to : $monthEnd;

            $columns[] = new StatementColumn(
                'm'.$cursor->format('Ym'),
                self::rangeLabel($start, $end),
                $start,
                $end,
                StatementColumn::ROLE_MONTH,
            );

            if (count($columns) > self::MAX_MONTH_COLUMNS) {
                return new StatementColumnSet(StatementColumnSet::MODE_NONE, $primary, [$primary], false, [
                    sprintf(
                        'Month-by-month columns cover at most %d months. Choose a shorter range to see them.',
                        self::MAX_MONTH_COLUMNS,
                    ),
                ]);
            }

            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        $columns[] = new StatementColumn(
            'total',
            'Total',
            $primary->from,
            $primary->to,
            StatementColumn::ROLE_TOTAL,
        );

        return new StatementColumnSet(StatementColumnSet::MODE_MONTHLY, $primary, $columns, false);
    }

    /**
     * A range as a reader would say it: `Jul 2026` for a whole month,
     * `15–31 Jul 2026` inside one, and both ends spelled out across months.
     */
    public static function rangeLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->isSameMonth($to)) {
            return $from->day === 1 && self::isMonthEnd($to)
                ? $from->format('M Y')
                : sprintf('%d–%d %s', $from->day, $to->day, $from->format('M Y'));
        }

        return $from->year === $to->year
            ? sprintf('%s – %s', $from->format('j M'), $to->format('j M Y'))
            : sprintf('%s – %s', $from->format('j M Y'), $to->format('j M Y'));
    }

    private static function isWholeMonths(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return $from->day === 1 && self::isMonthEnd($to);
    }

    private static function isMonthEnd(CarbonImmutable $date): bool
    {
        return $date->day === $date->daysInMonth;
    }
}
