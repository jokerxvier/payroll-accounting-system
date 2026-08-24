<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Pas\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Phase 5 Slice 8a — the Journal Report as xlsx or csv.
 *
 * One row per *line*, with the entry's own columns repeated across its lines
 * rather than left blank after the first. Blank-filled grouping looks tidier
 * on screen and is useless in a spreadsheet: it breaks sorting, filtering,
 * and pivoting, which is the entire reason someone asked for the xlsx.
 */
final class JournalReportExport implements FromCollection, WithHeadings
{
    /** @param Collection<int, JournalEntry> $entries */
    public function __construct(
        private readonly Collection $entries,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
    ) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'Date',
            'Entry',
            'Reference',
            'Narration',
            'Source',
            'Account code',
            'Account',
            'Line description',
            'Debit',
            'Credit',
        ];
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        $rows = collect();

        foreach ($this->entries as $entry) {
            foreach ($entry->lines as $line) {
                $rows->push([
                    $entry->date->toDateString(),
                    $entry->entry_number ?? '',
                    $entry->reference ?? '',
                    $entry->narration ?? '',
                    $entry->source_type ?? '',
                    $line->account?->code ?? '',
                    $line->account?->name ?? '',
                    $line->description ?? '',
                    $line->debit_centavos === 0 ? '' : self::pesos($line->debit_centavos),
                    $line->credit_centavos === 0 ? '' : self::pesos($line->credit_centavos),
                ]);
            }
        }

        $rows->push([
            '',
            'TOTAL',
            sprintf('%s to %s', $this->from->toDateString(), $this->to->toDateString()),
            '',
            '',
            '',
            '',
            '',
            self::pesos((int) $this->entries->sum('total_debit_centavos')),
            self::pesos((int) $this->entries->sum('total_credit_centavos')),
        ]);

        return $rows->values();
    }

    private static function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
