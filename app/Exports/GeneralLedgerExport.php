<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\Accounting\Reports\AccountLedger;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Phase 5 Slice 8a — one account's General Ledger as xlsx or csv.
 *
 * Opens with the balance brought forward and closes with the balance carried
 * forward, so the file reconciles on its own without the reader having to
 * fetch the prior period to find where the running balance started.
 */
final class GeneralLedgerExport implements FromCollection, WithHeadings
{
    public function __construct(private readonly AccountLedger $ledger) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'Date',
            'Entry',
            'Reference',
            'Description',
            'Contra accounts',
            'Debit',
            'Credit',
            'Balance',
        ];
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        $rows = collect([[
            $this->ledger->from?->toDateString() ?? '',
            '',
            '',
            'Balance brought forward',
            '',
            '',
            '',
            self::pesos($this->ledger->openingRawCentavos),
        ]]);

        foreach ($this->ledger->lines as $line) {
            $rows->push([
                $line->date->toDateString(),
                $line->entryNumber ?? '',
                $line->reference ?? '',
                $line->description ?? $line->narration ?? '',
                implode('; ', $line->contraAccounts),
                $line->debitCentavos === 0 ? '' : self::pesos($line->debitCentavos),
                $line->creditCentavos === 0 ? '' : self::pesos($line->creditCentavos),
                self::pesos($line->runningRawCentavos),
            ]);
        }

        $rows->push([
            $this->ledger->to->toDateString(),
            '',
            '',
            'Balance carried forward',
            '',
            self::pesos($this->ledger->totalDebitCentavos()),
            self::pesos($this->ledger->totalCreditCentavos()),
            self::pesos($this->ledger->closingRawCentavos()),
        ]);

        return $rows->values();
    }

    private static function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
