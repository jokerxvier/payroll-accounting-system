<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * The columns a statement was asked for, and what had to be said about them.
 *
 * `$mode` is the comparison that was actually applied, which is not always
 * the one requested: a month-by-month view of three years, or a custom
 * comparison with a date missing, falls back to no comparison and says so in
 * `$notices` rather than failing. The page reads `$mode` back into its filter
 * so what is selected always matches what is shown.
 */
final readonly class StatementColumnSet
{
    public const MODE_NONE = 'none';

    public const MODE_PREVIOUS_PERIOD = 'previous_period';

    public const MODE_PREVIOUS_YEAR = 'previous_year';

    public const MODE_MONTHLY = 'monthly';

    public const MODE_CUSTOM = 'custom';

    /** @var list<string> */
    public const MODES = [
        self::MODE_NONE,
        self::MODE_PREVIOUS_PERIOD,
        self::MODE_PREVIOUS_YEAR,
        self::MODE_MONTHLY,
        self::MODE_CUSTOM,
    ];

    /**
     * @param  StatementColumn  $range  the dates the statement reports on.
     *                                  Usually the first column, but not
     *                                  month by month, where the first
     *                                  column is only the first month
     * @param  list<StatementColumn>  $columns  in print order
     * @param  bool  $hasVariance  true only when there are exactly two
     *                             columns to compare; a run of months has no
     *                             single "change" to report
     * @param  list<string>  $notices
     */
    public function __construct(
        public string $mode,
        public StatementColumn $range,
        public array $columns,
        public bool $hasVariance,
        public array $notices = [],
    ) {}

    public function comparison(): ?StatementColumn
    {
        return $this->hasVariance ? $this->columns[1] : null;
    }

    /** @return list<array{key: string, label: string, from: string, to: string, role: string}> */
    public function columnsToArray(): array
    {
        return array_map(
            static fn (StatementColumn $column): array => $column->toArray(),
            $this->columns,
        );
    }
}
