<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Phase 5 Slice 1 — seeds the default school's chart of accounts and tax-rate
 * catalog: a general-purpose Philippine private-school book.
 *
 * Scope: the **default school only**. Every other school receives a copy
 * through `SchoolObserver`, which clones the default's catalogs on creation.
 * Seeding all schools here would fight that observer and overwrite tenants
 * that have already customised their chart.
 *
 * `school_id` is passed explicitly on every row rather than relying on the
 * `BelongsToTenant` auto-fill: `Tenant::current()` is null in seeder context,
 * so the trait would leave the column null and the NOT NULL constraint would
 * reject the insert. This is the same trap documented against
 * DemoCatalogSeeder in {@see DatabaseSeeder}.
 *
 * Idempotent — every row is keyed on `(school_id, code)` via updateOrCreate,
 * so re-running refreshes labels without duplicating accounts or disturbing
 * ids that journal lines may already reference. Production-safe.
 *
 * `normal_balance` is never written literally; it is derived through
 * {@see ChartOfAccount::normalBalanceForType()} so the seed cannot drift from
 * the rule the rest of the module applies.
 */
final class AccountingCatalogSeeder extends Seeder
{
    /**
     * The default chart. Ordered by code so the seeded table reads top-down
     * as a conventional book: assets, liabilities, equity, income, expense.
     *
     * `cash_flow_category` is set per account rather than inferred from
     * `type`, because it genuinely cannot be inferred — 5100 Salaries and
     * 5400 Interest Expense are both expenses, but only the first is an
     * operating cash flow.
     *
     * `cash` marks the accounts that ARE cash, which is a different question
     * again: 1400 Prepaid Expenses is an operating asset but paying a bill
     * out of it is meaningless. Only the two accounts flagged here may
     * receive or disburse money, and only they are summed as the cash
     * balance a Cash Flow Statement reconciles to.
     *
     * @var list<array{code: string, name: string, type: string, subtype: string, cash_flow: string, cash?: bool, system?: string, description?: string}>
     */
    private const ACCOUNTS = [
        // ── Assets ────────────────────────────────────────────────────────
        ['code' => '1100', 'name' => 'Cash on Hand', 'type' => 'asset', 'subtype' => 'current_asset', 'cash_flow' => 'operating', 'cash' => true],
        ['code' => '1110', 'name' => 'Cash in Bank', 'type' => 'asset', 'subtype' => 'current_asset', 'cash_flow' => 'operating', 'cash' => true],
        ['code' => '1200', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'current_asset', 'cash_flow' => 'operating', 'system' => ChartOfAccount::SYSTEM_AR_CONTROL, 'description' => 'Control account. Every approved sales invoice debits this; every receipt credits it. Do not post to it by hand.'],
        ['code' => '1210', 'name' => 'Allowance for Doubtful Accounts', 'type' => 'asset', 'subtype' => 'contra_asset', 'cash_flow' => 'operating'],
        ['code' => '1300', 'name' => 'Input VAT', 'type' => 'asset', 'subtype' => 'current_asset', 'cash_flow' => 'operating', 'system' => ChartOfAccount::SYSTEM_VAT_INPUT, 'description' => 'VAT paid on purchases, creditable against output VAT.'],
        ['code' => '1400', 'name' => 'Prepaid Expenses', 'type' => 'asset', 'subtype' => 'current_asset', 'cash_flow' => 'operating'],
        ['code' => '1450', 'name' => 'Advances to Suppliers', 'type' => 'asset', 'subtype' => 'current_asset', 'cash_flow' => 'operating', 'system' => ChartOfAccount::SYSTEM_SUPPLIER_ADVANCES, 'description' => 'Money paid to a supplier that no bill has claimed yet. Cleared as bills are allocated against it.'],
        ['code' => '1510', 'name' => 'Property, Plant and Equipment', 'type' => 'asset', 'subtype' => 'non_current_asset', 'cash_flow' => 'investing'],
        ['code' => '1520', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'subtype' => 'contra_asset', 'cash_flow' => 'investing'],

        // ── Liabilities ───────────────────────────────────────────────────
        ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'cash_flow' => 'operating', 'system' => ChartOfAccount::SYSTEM_AP_CONTROL, 'description' => 'Control account. Every approved supplier bill credits this; every disbursement debits it. Do not post to it by hand.'],
        ['code' => '2200', 'name' => 'Output VAT', 'type' => 'liability', 'subtype' => 'current_liability', 'cash_flow' => 'operating', 'system' => ChartOfAccount::SYSTEM_VAT_OUTPUT, 'description' => 'VAT collected on sales, owed to the BIR.'],
        ['code' => '2300', 'name' => 'Payroll Clearing', 'type' => 'liability', 'subtype' => 'current_liability', 'cash_flow' => 'operating', 'system' => ChartOfAccount::SYSTEM_PAYROLL_CLEARING, 'description' => 'Net pay accrued by a posted payroll run, cleared when the disbursement is recorded.'],
        ['code' => '2310', 'name' => 'SSS Contributions Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'cash_flow' => 'operating'],
        ['code' => '2320', 'name' => 'PhilHealth Contributions Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'cash_flow' => 'operating'],
        ['code' => '2330', 'name' => 'Pag-IBIG Contributions Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'cash_flow' => 'operating'],
        ['code' => '2340', 'name' => 'Withholding Tax Payable', 'type' => 'liability', 'subtype' => 'current_liability', 'cash_flow' => 'operating'],
        ['code' => '2410', 'name' => 'Advances from Customers', 'type' => 'liability', 'subtype' => 'current_liability', 'cash_flow' => 'operating', 'system' => ChartOfAccount::SYSTEM_CUSTOMER_ADVANCES, 'description' => 'Money received that no invoice has claimed yet. Distinct from Unearned Tuition Revenue, which is tuition billed but not yet earned.'],
        ['code' => '2400', 'name' => 'Unearned Tuition Revenue', 'type' => 'liability', 'subtype' => 'current_liability', 'cash_flow' => 'operating', 'description' => 'Tuition billed or collected in advance of the term it covers.'],
        ['code' => '2510', 'name' => 'Long-Term Loans Payable', 'type' => 'liability', 'subtype' => 'non_current_liability', 'cash_flow' => 'financing'],

        // ── Equity ────────────────────────────────────────────────────────
        ['code' => '3100', 'name' => "Owner's Capital", 'type' => 'equity', 'subtype' => 'equity', 'cash_flow' => 'financing'],
        ['code' => '3200', 'name' => 'Retained Earnings', 'type' => 'equity', 'subtype' => 'equity', 'cash_flow' => 'financing', 'system' => ChartOfAccount::SYSTEM_RETAINED_EARNINGS, 'description' => 'Accumulated profit. The year-end close rolls net income into this account.'],
        ['code' => '3300', 'name' => 'Dividends and Drawings', 'type' => 'equity', 'subtype' => 'contra_equity', 'cash_flow' => 'financing', 'description' => 'Distributions to owners. Subtracted in the Statement of Changes in Equity.'],

        // ── Income ────────────────────────────────────────────────────────
        ['code' => '4100', 'name' => 'Tuition Fee Income', 'type' => 'income', 'subtype' => 'operating_revenue', 'cash_flow' => 'operating'],
        ['code' => '4200', 'name' => 'Miscellaneous Fee Income', 'type' => 'income', 'subtype' => 'operating_revenue', 'cash_flow' => 'operating'],
        ['code' => '4900', 'name' => 'Other Income', 'type' => 'income', 'subtype' => 'other_income', 'cash_flow' => 'operating'],

        // ── Expenses ──────────────────────────────────────────────────────
        ['code' => '5100', 'name' => 'Salaries and Wages', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'operating'],
        ['code' => '5110', 'name' => 'SSS Contributions (Employer Share)', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'operating'],
        ['code' => '5120', 'name' => 'PhilHealth Contributions (Employer Share)', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'operating'],
        ['code' => '5130', 'name' => 'Pag-IBIG Contributions (Employer Share)', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'operating'],
        ['code' => '5140', // separate from 5100 so the 13th-month accrual is reportable on its own
            'name' => '13th Month Pay', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'operating'],
        ['code' => '5200', 'name' => 'Rent Expense', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'operating'],
        ['code' => '5210', 'name' => 'Utilities Expense', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'operating'],
        ['code' => '5220', 'name' => 'Office Supplies', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'operating'],
        ['code' => '5230', 'name' => 'Repairs and Maintenance', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'operating'],
        ['code' => '5240', 'name' => 'Professional Fees', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'operating'],
        ['code' => '5300', 'name' => 'Depreciation Expense', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'none', 'description' => 'Non-cash. Excluded from the Cash Flow Statement, hence cash_flow_category = none.'],
        ['code' => '5400', 'name' => 'Interest Expense', 'type' => 'expense', 'subtype' => 'other_expense', 'cash_flow' => 'financing'],
        ['code' => '5900', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'subtype' => 'operating_expense', 'cash_flow' => 'operating'],
    ];

    public function run(): void
    {
        $defaultSchool = School::query()
            ->withoutGlobalScopes()
            ->where('slug', 'default')
            ->first();

        // Fresh-bootstrap env where SchoolSeeder hasn't run. Skip silently —
        // the same defensive pattern the Phase-D migrations use.
        if ($defaultSchool === null) {
            return;
        }

        $schoolId = (int) $defaultSchool->getKey();

        $this->seedChartOfAccounts($schoolId);
        $this->seedTaxRates($schoolId);
    }

    private function seedChartOfAccounts(int $schoolId): void
    {
        foreach (self::ACCOUNTS as $account) {
            ChartOfAccount::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'school_id' => $schoolId,
                    'code' => $account['code'],
                ],
                [
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'subtype' => $account['subtype'],
                    'normal_balance' => ChartOfAccount::normalBalanceForType($account['type']),
                    'cash_flow_category' => $account['cash_flow'],
                    'is_cash_equivalent' => $account['cash'] ?? false,
                    'system_code' => $account['system'] ?? null,
                    'description' => $account['description'] ?? null,
                    'is_active' => true,
                    // Accounts the software posts to by `system_code` are
                    // locked: deleting or re-coding one breaks posting.
                    'is_locked' => isset($account['system']),
                ],
            );
        }
    }

    /**
     * The four rates a Philippine VAT-registered school needs.
     *
     * Exempt and zero-rated both carry `rate_bps = 0` but remain distinct
     * types: BIR requires VAT-exempt sales and zero-rated sales reported as
     * separate subtotals on the invoice, so they cannot collapse into one
     * "0%" rate.
     */
    private function seedTaxRates(int $schoolId): void
    {
        $outputVatAccountId = $this->systemAccountId($schoolId, ChartOfAccount::SYSTEM_VAT_OUTPUT);
        $inputVatAccountId = $this->systemAccountId($schoolId, ChartOfAccount::SYSTEM_VAT_INPUT);

        $rates = [
            [
                'code' => 'VAT_12_SALES',
                'name' => 'VAT 12% (Sales)',
                'rate_bps' => 1200,
                'type' => TaxRate::TYPE_VAT_SALES,
                'account_id' => $outputVatAccountId,
            ],
            [
                'code' => 'VAT_12_PURCHASE',
                'name' => 'VAT 12% (Purchases)',
                'rate_bps' => 1200,
                'type' => TaxRate::TYPE_VAT_PURCHASE,
                'account_id' => $inputVatAccountId,
            ],
            [
                'code' => 'VAT_EXEMPT',
                'name' => 'VAT Exempt',
                'rate_bps' => 0,
                'type' => TaxRate::TYPE_EXEMPT,
                'account_id' => null,
            ],
            [
                'code' => 'VAT_ZERO',
                'name' => 'Zero-Rated',
                'rate_bps' => 0,
                'type' => TaxRate::TYPE_ZERO_RATED,
                'account_id' => null,
            ],
        ];

        foreach ($rates as $rate) {
            TaxRate::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'school_id' => $schoolId,
                    'code' => $rate['code'],
                ],
                [
                    'name' => $rate['name'],
                    'rate_bps' => $rate['rate_bps'],
                    'type' => $rate['type'],
                    'account_id' => $rate['account_id'],
                    'is_active' => true,
                ],
            );
        }
    }

    private function systemAccountId(int $schoolId, string $systemCode): ?int
    {
        $id = ChartOfAccount::query()
            ->withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('system_code', $systemCode)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
