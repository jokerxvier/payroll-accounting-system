<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Pas\ChartOfAccount;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The cutover worksheet: one row per account that can hold an opening
 * balance, with both amount columns left empty for the client to fill.
 *
 * Only assets, liabilities and equity are listed. Income and expense
 * accounts are omitted rather than included-and-rejected because the
 * template is the earliest place the rule can be taught — a sheet that never
 * offers the wrong row cannot collect the wrong figure, and the importer's
 * matching refusal then only has to catch rows someone added by hand.
 *
 * Inactive accounts are omitted for the same reason. An account nobody may
 * post to today is not one to open a balance on.
 *
 * Amounts are decimals, not centavos — unlike the employee bulk template,
 * which round-trips its own stored integers. A person reading a trial
 * balance off their old system types 1,234.56, and asking them to convert to
 * 123456 invites exactly the error this import exists to avoid. The request
 * converts on the way in.
 */
final class OpeningBalanceTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'account_code',
            'account_name (read-only)',
            'type (read-only)',
            'opening_debit',
            'opening_credit',
        ];
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    public function collection(): Collection
    {
        return ChartOfAccount::query()
            ->active()
            ->whereIn('type', [
                ChartOfAccount::TYPE_ASSET,
                ChartOfAccount::TYPE_LIABILITY,
                ChartOfAccount::TYPE_EQUITY,
            ])
            ->orderBy('code')
            ->get()
            ->map(fn (ChartOfAccount $account): array => $this->row($account));
    }

    /**
     * @return array<string, string|null>
     */
    private function row(ChartOfAccount $account): array
    {
        return [
            'account_code' => $account->code,
            'account_name' => $account->name,
            'type' => $account->type,
            'opening_debit' => null,
            'opening_credit' => null,
        ];
    }
}
