<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\Accounting\Reports\TrialBalance;
use App\Services\Accounting\Reports\TrialBalanceRow;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Phase 5 Slice 8a — Trial Balance as xlsx or csv.
 *
 * Money is written in pesos as a decimal string, not centavos, because the
 * file is opened in a spreadsheet and summed there. Emitting `112000` for
 * ₱1,120.00 would make every hand-check wrong by a factor of a hundred.
 *
 * The totals row is part of the export rather than left to the reader's
 * SUM(): the whole value of a trial balance is the two columns agreeing, and
 * a file that omits the proof is missing the report's only conclusion.
 */
final class TrialBalanceExport implements FromCollection, WithHeadings
{
    public function __construct(private readonly TrialBalance $trialBalance) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'Code',
            'Account',
            'Type',
            'Opening Dr',
            'Opening Cr',
            'Period Dr',
            'Period Cr',
            'Closing Dr',
            'Closing Cr',
        ];
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        $rows = collect($this->trialBalance->rows)
            ->map(fn (TrialBalanceRow $row): array => [
                $row->code,
                $row->name,
                $row->type,
                self::pesos($row->openingBalanceDebitCentavos()),
                self::pesos($row->openingBalanceCreditCentavos()),
                self::pesos($row->periodDebitCentavos),
                self::pesos($row->periodCreditCentavos),
                self::pesos($row->closingBalanceDebitCentavos()),
                self::pesos($row->closingBalanceCreditCentavos()),
            ]);

        $rows->push([
            '',
            'TOTAL',
            '',
            self::pesos($this->trialBalance->totalOpeningDebitCentavos()),
            self::pesos($this->trialBalance->totalOpeningCreditCentavos()),
            self::pesos($this->trialBalance->totalPeriodDebitCentavos()),
            self::pesos($this->trialBalance->totalPeriodCreditCentavos()),
            self::pesos($this->trialBalance->totalClosingDebitCentavos()),
            self::pesos($this->trialBalance->totalClosingCreditCentavos()),
        ]);

        return $rows->values();
    }

    private static function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
