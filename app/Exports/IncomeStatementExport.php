<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\Accounting\Reports\IncomeStatement;
use App\Services\Accounting\Reports\StatementColumn;
use App\Services\Accounting\Reports\StatementLine;
use App\Services\Accounting\Reports\StatementSectionMap;
use App\Support\Centavos;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Phase 5 Slice 8b — the Income Statement as xlsx or csv.
 *
 * One amount column per column of the statement, so the file has as many as
 * the comparison that was asked for: one, two with their variance, or a run
 * of months and a total.
 *
 * Money is pesos as a decimal string, as in `TrialBalanceExport`, and signed:
 * a contra or a loss is a negative number a spreadsheet can sum, not a figure
 * in brackets it would read as text.
 */
final class IncomeStatementExport implements FromCollection, WithHeadings
{
    public function __construct(private readonly IncomeStatement $statement) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'Section',
            'Code',
            'Account',
            ...array_map(
                static fn (StatementColumn $column): string => $column->label,
                $this->statement->columns->columns,
            ),
            ...($this->statement->columns->hasVariance ? ['Variance', 'Variance %'] : []),
        ];
    }

    /** @return Collection<int, list<string>> */
    public function collection(): Collection
    {
        $rows = new Collection;

        foreach ($this->statement->lines as $line) {
            // A heading has no figures; its name is the Section column of
            // every line beneath it.
            if ($line->kind !== StatementLine::KIND_HEADING) {
                $rows->push($this->row($line));
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private function row(StatementLine $line): array
    {
        $row = [
            StatementSectionMap::LABELS[$line->section] ?? '',
            $line->code ?? '',
            $line->name,
        ];

        foreach ($line->amountsCentavos as $amount) {
            $row[] = Centavos::decimal($amount);
        }

        if ($this->statement->columns->hasVariance) {
            $row[] = $line->varianceCentavos === null ? '' : Centavos::decimal($line->varianceCentavos);
            $row[] = Centavos::percentFromBasisPoints($line->varianceBasisPoints);
        }

        return $row;
    }
}
