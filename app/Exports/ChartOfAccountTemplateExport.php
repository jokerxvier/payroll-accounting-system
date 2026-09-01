<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Pas\ChartOfAccount;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * An empty chart, for a school building one from scratch.
 *
 * Same headings as {@see ChartOfAccountExport} so the two are interchangeable:
 * the template is what you download when there is nothing to export yet.
 *
 * Four example rows rather than one. `type` has five legal values and
 * `cash_flow_category` four, and the combinations that matter are not
 * guessable from a single line — an expense is operating, equipment is
 * investing, a bank account is a cash equivalent and an ordinary receivable is
 * not. Showing a nested account is the only way to convey that `parent_code`
 * takes a code from this same sheet.
 */
final class ChartOfAccountTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return (new ChartOfAccountExport)->headings();
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    public function collection(): Collection
    {
        /** @var list<array<string, string|null>> $rows */
        $rows = [
            $this->row('1100', '(delete this row) Cash on Hand', ChartOfAccount::TYPE_ASSET, 'current_asset', null, ChartOfAccount::CASH_FLOW_OPERATING, 'yes', 'Petty cash float'),
            // Nested, to show that parent_code takes a code from this sheet.
            $this->row('1110', '(delete this row) Cash in Bank', ChartOfAccount::TYPE_ASSET, 'current_asset', '1100', ChartOfAccount::CASH_FLOW_OPERATING, 'yes', null),
            $this->row('1510', '(delete this row) Equipment', ChartOfAccount::TYPE_ASSET, 'fixed_asset', null, ChartOfAccount::CASH_FLOW_INVESTING, 'no', 'Bought and sold, not spent'),
            $this->row('5210', '(delete this row) Utilities Expense', ChartOfAccount::TYPE_EXPENSE, 'operating_expense', null, ChartOfAccount::CASH_FLOW_OPERATING, 'no', null),
        ];

        return new Collection($rows);
    }

    /**
     * @return array<string, string|null>
     */
    private function row(
        string $code,
        string $name,
        string $type,
        string $subtype,
        ?string $parentCode,
        string $cashFlow,
        string $isCashEquivalent,
        ?string $description,
    ): array {
        return [
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'subtype' => $subtype,
            'parent_code' => $parentCode,
            'cash_flow_category' => $cashFlow,
            'is_cash_equivalent' => $isCashEquivalent,
            'is_active' => 'yes',
            'description' => $description,
            // The read-only columns are left blank rather than omitted: the
            // sheet has to keep the same shape as the export, and filling
            // them in would suggest they are yours to set.
            'normal_balance' => null,
            'system_code' => null,
            'is_locked' => null,
        ];
    }
}
