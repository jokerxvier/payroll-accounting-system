<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\Accounting\Reports\BalanceSheet;
use App\Services\Accounting\Reports\BalanceSheetColumn;
use App\Services\Accounting\Reports\StatementLine;
use App\Services\Accounting\Reports\StatementSectionMap;
use App\Support\Centavos;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Phase 5 Slice 8b — the Balance Sheet as xlsx or csv.
 *
 * Closes with the check the statement exists to make: assets less liabilities
 * and equity. As with the Trial Balance's totals row, a file that left the
 * proof to the reader's own SUM() would be missing the conclusion.
 *
 * The lines itemising Retained Earnings are included and marked, because they
 * are part of what the statement says — but they are not additional to the
 * line above them, and a reader summing the column must be able to tell.
 */
final class BalanceSheetExport implements FromCollection, WithHeadings
{
    public function __construct(private readonly BalanceSheet $sheet) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'Section',
            'Code',
            'Account',
            ...array_map(
                static fn (BalanceSheetColumn $column): string => $column->label(),
                $this->sheet->columns,
            ),
            ...($this->sheet->hasVariance() ? ['Variance', 'Variance %'] : []),
            'Note',
        ];
    }

    /** @return Collection<int, list<string>> */
    public function collection(): Collection
    {
        $rows = new Collection;

        foreach ($this->sheet->lines as $line) {
            if ($line->kind !== StatementLine::KIND_HEADING) {
                $rows->push($this->row($line));
            }
        }

        $rows->push($this->balanceCheck());

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

        if ($this->sheet->hasVariance()) {
            $row[] = $line->varianceCentavos === null ? '' : Centavos::decimal($line->varianceCentavos);
            $row[] = Centavos::percentFromBasisPoints($line->varianceBasisPoints);
        }

        $row[] = match ($line->kind) {
            StatementLine::KIND_DETAIL => 'Included in the line above',
            StatementLine::KIND_COMPUTED => 'Computed from income and expense accounts',
            default => '',
        };

        return $row;
    }

    /**
     * One check per date: a statement comparing two dates is two statements,
     * and either can be the one that does not balance.
     *
     * @return list<string>
     */
    private function balanceCheck(): array
    {
        $row = ['', '', 'Assets less liabilities and equity'];

        foreach ($this->sheet->columns as $column) {
            $row[] = Centavos::decimal($column->varianceCentavos());
        }

        if ($this->sheet->hasVariance()) {
            array_push($row, '', '');
        }

        $row[] = $this->sheet->isBalanced() ? 'Balanced' : 'NOT BALANCED';

        return $row;
    }
}
