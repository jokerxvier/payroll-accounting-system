<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Pas\ChartOfAccount;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * An empty contact sheet, for a school starting from nothing.
 *
 * Same headings as {@see ContactExport}, so the two are interchangeable: the
 * template is what you download when there is nothing to export yet, and the
 * export is what you download once there is.
 *
 * Two example rows rather than a bare heading row. Half the columns have a
 * shape that is far quicker to copy than to describe — yes/no flags, a TIN
 * with no punctuation, an account referenced by code — and the second row
 * shows that the optional columns really are optional, which one row alone
 * cannot.
 */
final class ContactTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return (new ContactExport)->headings();
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    public function collection(): Collection
    {
        /** @var list<array<string, string|null>> $rows */
        $rows = [
            [
                'code' => 'C-0001',
                'name' => '(delete this row) Dela Cruz Family',
                'is_customer' => 'yes',
                'is_supplier' => 'no',
                'tin' => '123456789',
                'email' => 'family@example.com',
                'phone' => '0917 555 0100',
                'address' => '12 Mabini St, Quezon City',
                'receivable_account_code' => $this->exampleCode(ChartOfAccount::SYSTEM_AR_CONTROL),
                'payable_account_code' => null,
                'is_active' => 'yes',
                'notes' => 'Two children enrolled',
            ],
            [
                // The minimum a row can carry: everything else is optional.
                'code' => 'S-0001',
                'name' => '(delete this row) Acme Trading',
                'is_customer' => 'no',
                'is_supplier' => 'yes',
                'tin' => null,
                'email' => null,
                'phone' => null,
                'address' => null,
                'receivable_account_code' => null,
                'payable_account_code' => null,
                'is_active' => 'yes',
                'notes' => null,
            ],
        ];

        return new Collection($rows);
    }

    /**
     * A real code from this school's chart, so the example is one the importer
     * would actually accept. Null when the chart has no such account, which
     * leaves the cell blank rather than suggesting a code that does not exist.
     */
    private function exampleCode(string $systemCode): ?string
    {
        return ChartOfAccount::query()
            ->where('system_code', $systemCode)
            ->value('code');
    }
}
