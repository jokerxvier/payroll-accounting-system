/**
 * Wire shapes for the Balance Sheet and Income Statement (Phase 5 Slice 8b).
 *
 * A statement arrives as a flat list of lines already in print order —
 * headings, accounts, subtotals and totals interleaved — so the page decides
 * how a line looks and nothing about which lines there are. The spreadsheet
 * and the PDF are built from the same list.
 *
 * Amounts are centavos in each account's natural direction: an ordinary
 * balance is positive, and a contra account or a loss is negative.
 */
import type { DashboardPreset } from '@/types/financial-dashboard';

export type StatementCompareMode =
    | 'none'
    | 'previous_period'
    | 'previous_year'
    | 'monthly'
    | 'custom';

export interface StatementColumn {
    key: string;
    label: string;
    from: string;
    to: string;
    role: 'primary' | 'comparison' | 'month' | 'total';
}

export type StatementLineKind =
    | 'heading'
    | 'account'
    /** Derived at read time; no single account holds it. */
    | 'computed'
    /** Itemises the line above it. Not added to any subtotal. */
    | 'detail'
    | 'subtotal'
    | 'total';

export interface StatementDrill {
    report: 'general-ledger' | 'income-statement';
    from: string;
    to: string;
}

export interface StatementLine {
    kind: StatementLineKind;
    key: string;
    section: string;
    name: string;
    /** One per column, in column order. Empty on a heading. */
    amounts_centavos: number[];
    account_id: number | null;
    code: string | null;
    is_active: boolean;
    /** Placed in its section by default, for want of a recognised subtype. */
    is_unclassified: boolean;
    /** Current less comparison. Null unless two columns are compared. */
    variance_centavos: number | null;
    /** Basis points of the comparison; null when the comparison was nil. */
    variance_bp: number | null;
    /** Whether the change is good news — a rise in an expense is not. */
    variance_favourable: boolean | null;
    /**
     * Where the figure can be read in full. Null on an Income Statement
     * account line, where each column links to its own dates instead.
     */
    drill: StatementDrill | null;
}

export type BalanceSheetCompareMode =
    | 'none'
    | 'previous_month_end'
    | 'previous_year_end'
    | 'custom';

/**
 * One date on a Balance Sheet, with its own proof that it balances. Two
 * dates side by side are two statements, and either can be the one that
 * does not.
 */
export interface BalanceSheetColumn {
    label: string;
    as_at: string;
    /** Where "this year" begins for this date's earnings. */
    fiscal_year_start: string;
    assets_centavos: number;
    liabilities_centavos: number;
    equity_centavos: number;
    liabilities_and_equity_centavos: number;
    is_balanced: boolean;
    /** Assets less liabilities and equity. */
    variance_centavos: number;
}

export interface BalanceSheetPageProps {
    filters: {
        as_at: string;
        /** The comparison applied, which may not be the one asked for. */
        compare: BalanceSheetCompareMode;
        compare_as_at: string | null;
        include_zero: boolean;
    };
    columns: BalanceSheetColumn[];
    hasVariance: boolean;
    notices: string[];
    lines: StatementLine[];
    booksOpenedOn?: string | null;
}

export interface IncomeStatementFilters {
    preset: DashboardPreset;
    from: string;
    to: string;
    /** The comparison applied, which may not be the one asked for. */
    compare: StatementCompareMode;
    compare_from: string | null;
    compare_to: string | null;
    include_zero: boolean;
}

export interface IncomeStatementPageProps {
    filters: IncomeStatementFilters;
    columns: StatementColumn[];
    hasVariance: boolean;
    notices: string[];
    lines: StatementLine[];
    booksOpenedOn?: string | null;
    /** The report this one was opened from, when it was. */
    backHref?: string | null;
}
