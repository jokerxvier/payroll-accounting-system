/**
 * Phase 5 Slice 1 — ledger foundation types.
 *
 * Mirrors the columns exposed by App\Http\Controllers\Admin\Accounting\*.
 * Money never appears here: the chart of accounts, tax rates, and periods
 * are all configuration. Amounts arrive with Slice 2's journal entries.
 */

export type AccountType =
    | 'asset'
    | 'liability'
    | 'equity'
    | 'income'
    | 'expense';

export type NormalBalance = 'debit' | 'credit';

export type CashFlowCategory = 'operating' | 'investing' | 'financing' | 'none';

export interface ChartOfAccountRow {
    id: number;
    code: string;
    name: string;
    type: AccountType;
    subtype: string | null;
    /**
     * Derived server-side from `type`, never chosen by the operator. Drives
     * the sign of every General Ledger and Balance Sheet figure.
     */
    normal_balance: NormalBalance;
    cash_flow_category: CashFlowCategory;
    parent_id: number | null;
    /**
     * Non-null on accounts the software itself posts to (AR control, VAT
     * output, payroll clearing, …). Such rows are `is_locked`.
     */
    system_code: string | null;
    description: string | null;
    is_active: boolean;
    is_locked: boolean;
}

/** Lean shape returned for parent / posting-account pickers. */
export interface AccountOption {
    id: number;
    code: string;
    name: string;
    type: AccountType;
}

export type TaxRateType =
    | 'vat_sales'
    | 'vat_purchase'
    | 'exempt'
    | 'zero_rated';

export interface TaxRateRow {
    id: number;
    code: string;
    name: string;
    /** Basis points — 12% is 1200. Never a float percentage. */
    rate_bps: number;
    type: TaxRateType;
    account_id: number | null;
    account: Pick<AccountOption, 'id' | 'code' | 'name'> | null;
    is_active: boolean;
}

export type AccountingPeriodStatus = 'open' | 'closed';

export interface AccountingPeriodRow {
    id: number;
    code: string;
    name: string | null;
    /** ISO date strings (YYYY-MM-DD) — serialised by the controller. */
    start_date: string;
    end_date: string;
    fiscal_year: number | null;
    status: AccountingPeriodStatus;
    closed_at: string | null;
    reopened_at: string | null;
    /**
     * Per-row policy results. The client renders transitions from these
     * rather than re-deriving authorization or legality in TypeScript.
     */
    can: {
        update: boolean;
        close: boolean;
        reopen: boolean;
    };
}

/** Shape of the editable period passed to the edit page (no `can` block). */
export interface AccountingPeriodEditable {
    id: number;
    code: string;
    name: string | null;
    start_date: string;
    end_date: string;
    fiscal_year: number | null;
    status: AccountingPeriodStatus;
}
