<?php

declare(strict_types=1);

namespace App\Services\Accounting\Reports;

use App\Models\Pas\ChartOfAccount;

/**
 * Which section of a statement an account belongs in.
 *
 * The only place `subtype` is read for layout. `subtype` is a free string on
 * the chart — the seeder writes a known set, but a school can add an account
 * with any value or none — so every type has a section that takes whatever is
 * left, and the row is marked unclassified so the page can say so. An account
 * is never dropped for being unclassified: that would unbalance the statement
 * to punish a labelling gap.
 *
 * Grouping is by `subtype`, not `parent_id`. The seeded chart is flat, so a
 * parent roll-up would have nothing to roll up.
 */
final class StatementSectionMap
{
    public const CURRENT_ASSETS = 'current_assets';

    public const NON_CURRENT_ASSETS = 'non_current_assets';

    public const CURRENT_LIABILITIES = 'current_liabilities';

    public const NON_CURRENT_LIABILITIES = 'non_current_liabilities';

    public const EQUITY = 'equity';

    public const REVENUE = 'revenue';

    public const OPERATING_EXPENSES = 'operating_expenses';

    public const OTHER_INCOME = 'other_income';

    public const OTHER_EXPENSES = 'other_expenses';

    /** @var array<string, string> */
    public const LABELS = [
        self::CURRENT_ASSETS => 'Current assets',
        self::NON_CURRENT_ASSETS => 'Non-current assets',
        self::CURRENT_LIABILITIES => 'Current liabilities',
        self::NON_CURRENT_LIABILITIES => 'Non-current liabilities',
        self::EQUITY => 'Equity',
        self::REVENUE => 'Revenue',
        self::OPERATING_EXPENSES => 'Operating expenses',
        self::OTHER_INCOME => 'Other income',
        self::OTHER_EXPENSES => 'Other expenses',
    ];

    /**
     * @return array{0: string, 1: bool} the section key, and whether the
     *                                   account landed there by default
     */
    public static function sectionFor(string $type, ?string $subtype, ?string $cashFlowCategory): array
    {
        return match ($type) {
            ChartOfAccount::TYPE_ASSET => match ($subtype) {
                'current_asset' => [self::CURRENT_ASSETS, false],
                'non_current_asset' => [self::NON_CURRENT_ASSETS, false],
                'contra_asset' => [self::contraAssetSection($cashFlowCategory), false],
                default => [self::CURRENT_ASSETS, true],
            },
            ChartOfAccount::TYPE_LIABILITY => match ($subtype) {
                'current_liability' => [self::CURRENT_LIABILITIES, false],
                'non_current_liability' => [self::NON_CURRENT_LIABILITIES, false],
                default => [self::CURRENT_LIABILITIES, true],
            },
            // One section, so there is nowhere to be misplaced.
            ChartOfAccount::TYPE_EQUITY => [self::EQUITY, false],
            ChartOfAccount::TYPE_INCOME => match ($subtype) {
                'operating_revenue' => [self::REVENUE, false],
                'other_income' => [self::OTHER_INCOME, false],
                default => [self::REVENUE, true],
            },
            default => match ($subtype) {
                'operating_expense' => [self::OPERATING_EXPENSES, false],
                'other_expense' => [self::OTHER_EXPENSES, false],
                default => [self::OPERATING_EXPENSES, true],
            },
        };
    }

    /**
     * A contra asset sits beside the asset it reduces, and `contra_asset`
     * does not say which that is: Allowance for Doubtful Accounts reduces a
     * current asset, Accumulated Depreciation a non-current one.
     *
     * `cash_flow_category` is the nearest thing the chart records. Fixed
     * assets are bought and sold as investing activity, so a contra account
     * in that category is taken to belong with them, and any other with the
     * current assets. This is an inference, right for the seeded chart and
     * not guaranteed beyond it — total assets are correct either way, only
     * the two subtotals move.
     */
    private static function contraAssetSection(?string $cashFlowCategory): string
    {
        return $cashFlowCategory === ChartOfAccount::CASH_FLOW_INVESTING
            ? self::NON_CURRENT_ASSETS
            : self::CURRENT_ASSETS;
    }
}
