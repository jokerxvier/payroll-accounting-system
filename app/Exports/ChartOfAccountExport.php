<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Pas\ChartOfAccount;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The chart, in the shape the importer reads back.
 *
 * `code` is the join key, as it is on the contact register — a row whose code
 * exists updates that account, a new one creates it. Changing a code in the
 * sheet does not renumber an account, it creates a second one, which is why
 * the warning is in the heading rather than beside the download button.
 *
 * **Three columns are read-only, and the headings say so.** They are exported
 * because an accountant reading the file wants them, and refused on the way
 * back in because each would do real damage:
 *
 *   - `normal_balance` is DERIVED from `type` ({@see ChartOfAccount::normalBalanceForType()}).
 *     Accepting it from a sheet would let someone mark an expense account
 *     credit-normal and invert the sign of every figure it reports.
 *   - `system_code` is what the software finds its own accounts by. Moving it
 *     would point AR posting at whatever row inherited the sentinel.
 *   - `is_locked` is the flag protecting those rows; a sheet that could clear
 *     it could then rewrite them.
 *
 * The parent is exported as a CODE, never an id — an id means nothing to the
 * person editing the file, and `1100` is the same account to anyone who reads
 * a trial balance.
 */
final class ChartOfAccountExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'code (do not change)',
            'name',
            'type',
            'subtype',
            'parent_code',
            'cash_flow_category',
            'is_cash_equivalent',
            'is_active',
            'description',
            'normal_balance (read-only)',
            'system_code (read-only)',
            'is_locked (read-only)',
        ];
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    public function collection(): Collection
    {
        /** @var list<array<string, string|null>> $rows */
        $rows = [];

        $accounts = ChartOfAccount::query()
            ->with('parent:id,code')
            ->orderBy('code')
            ->get();

        foreach ($accounts as $account) {
            $rows[] = [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'subtype' => $account->subtype,
                'parent_code' => $account->parent?->code,
                'cash_flow_category' => $account->cash_flow_category,
                // "yes"/"no" rather than 1/0: a spreadsheet renders a bare 1
                // as a number and the next reader cannot tell what it means.
                'is_cash_equivalent' => $account->is_cash_equivalent ? 'yes' : 'no',
                'is_active' => $account->is_active ? 'yes' : 'no',
                'description' => $account->description,
                'normal_balance' => $account->normal_balance,
                'system_code' => $account->system_code,
                'is_locked' => $account->is_locked ? 'yes' : 'no',
            ];
        }

        return new Collection($rows);
    }
}
