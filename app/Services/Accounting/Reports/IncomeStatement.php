<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

/**
 * An Income Statement: what was earned and spent inside each column's dates.
 *
 * Every figure is a period movement. None of them is a balance, and none of
 * them includes anything from before its column's first day.
 */
final readonly class IncomeStatement
{
    /**
     * @param  list<StatementLine>  $lines  in print order
     * @param  list<int>  $netIncomeCentavos  one per column
     */
    public function __construct(
        public StatementColumnSet $columns,
        public array $lines,
        public array $netIncomeCentavos,
    ) {}

    public function hasAccountLines(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->kind === StatementLine::KIND_ACCOUNT) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    public function linesToArray(): array
    {
        return array_map(
            static fn (StatementLine $line): array => $line->toArray(),
            $this->lines,
        );
    }
}
