<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * A Balance Sheet: what the school holds and owes as at one date, or as at
 * two set side by side.
 *
 * Two of its equity figures are not account balances. Nothing in this system
 * closes a year — income and expense accounts are never zeroed into Retained
 * Earnings — so the profit they hold is added to equity here, at read time:
 * earlier years' into Retained Earnings, this year's as its own line. Without
 * that the statement cannot balance, because the ledger's profit would be
 * missing from the side that is supposed to contain it.
 */
final readonly class BalanceSheet
{
    public const COMPARE_NONE = 'none';

    public const COMPARE_PREVIOUS_MONTH_END = 'previous_month_end';

    public const COMPARE_PREVIOUS_YEAR_END = 'previous_year_end';

    public const COMPARE_CUSTOM = 'custom';

    /** @var list<string> */
    public const COMPARISONS = [
        self::COMPARE_NONE,
        self::COMPARE_PREVIOUS_MONTH_END,
        self::COMPARE_PREVIOUS_YEAR_END,
        self::COMPARE_CUSTOM,
    ];

    /**
     * @param  non-empty-list<BalanceSheetColumn>  $columns  the date asked
     *                                                       for, then the one
     *                                                       it is compared to
     * @param  list<StatementLine>  $lines  in print order
     */
    public function __construct(
        public array $columns,
        public array $lines,
    ) {}

    public function primary(): BalanceSheetColumn
    {
        return $this->columns[0];
    }

    public function comparison(): ?BalanceSheetColumn
    {
        return $this->columns[1] ?? null;
    }

    public function hasVariance(): bool
    {
        return count($this->columns) === 2;
    }

    /** Whether every date on the statement balances. */
    public function isBalanced(): bool
    {
        foreach ($this->columns as $column) {
            if (! $column->isBalanced()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function linesToArray(): array
    {
        return array_map(
            static fn (StatementLine $line): array => $line->toArray(),
            $this->lines,
        );
    }

    /** @return list<array<string, int|bool|string>> */
    public function columnsToArray(): array
    {
        return array_map(
            static fn (BalanceSheetColumn $column): array => $column->toArray(),
            $this->columns,
        );
    }
}
