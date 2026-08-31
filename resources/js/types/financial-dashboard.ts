/**
 * The accounting dashboard's payload.
 *
 * Money is integer centavos, as everywhere else on the wire; the page divides
 * by 100 once, at the point of display. Any change here must land in lockstep
 * with `app/Services/Accounting/Reports/AccountingSummary.php` and
 * `LedgerSeriesService`.
 */

/** The ranges the page offers without asking anyone to pick dates. */
export type DashboardPreset = 'month' | 'quarter' | 'year' | 'custom';

/** One income account's earnings for the range. */
export interface RevenueByAccountRow {
    account_id: number;
    code: string;
    name: string;
    centavos: number;
}

/**
 * The six tiles, plus the revenue breakdown.
 *
 * Two kinds of figure live here and they answer different questions. Income,
 * expenses and net income are **period movements** — what happened between
 * these dates. Cash, receivables and payables are **balances** — what the
 * school holds and is owed as at the end of the range, opening included.
 */
export interface AccountingSummaryProps {
    from: string | null;
    to: string;
    cash_centavos: number;
    receivables_centavos: number;
    payables_centavos: number;
    income_centavos: number;
    expenses_centavos: number;
    net_income_centavos: number;
    revenue_by_account: RevenueByAccountRow[];
}

/**
 * One month of the income-vs-expenses chart.
 *
 * The series is dense: a month with nothing posted comes back zeroed rather
 * than absent, because a gap in a bar chart reads as missing data instead of
 * as a quiet month.
 */
export interface MonthlySeriesPoint {
    /** `YYYY-MM`, for keys and sorting. */
    month: string;
    /** `Aug 2026`, for the axis. */
    label: string;
    income_centavos: number;
    expenses_centavos: number;
}

export interface AccountingDashboardPageProps {
    filters: {
        preset: DashboardPreset;
        from: string;
        to: string;
    };
    summary: AccountingSummaryProps;
    monthlySeries: MonthlySeriesPoint[];
}

/* ── The invoice dashboard ──────────────────────────────────────────── */

/** One ageing bucket, in days past due. */
export interface AgingBucket {
    key: 'current' | '1_30' | '31_60' | '61_90' | 'over_90';
    label: string;
    centavos: number;
}

/**
 * One slice of the invoice status breakdown.
 *
 * `overdue` is a cut ACROSS `unpaid` and `partially_paid`, not a fourth kind —
 * so it is rendered alongside them rather than as another slice of the same
 * whole, and it carries the unpaid remainder where the others carry the
 * invoice total.
 */
export interface InvoiceStatusSlice {
    key: 'paid' | 'partially_paid' | 'unpaid' | 'overdue';
    label: string;
    count: number;
    centavos: number;
}

/** One month of billed-against-collected. */
export interface CollectionsPoint {
    month: string;
    label: string;
    invoiced_centavos: number;
    collected_centavos: number;
}

/** One payer on the outstanding table. */
export interface OutstandingPayerRow {
    contact_id: number;
    contact_name: string;
    /** Snapshot names off their invoices — one row per family, not per child. */
    students: string[];
    invoiced_centavos: number;
    paid_centavos: number;
    outstanding_centavos: number;
    oldest_due_date: string | null;
    days_overdue: number;
    status: 'overdue' | 'partially_paid' | 'unpaid';
}

/**
 * The invoice dashboard's payload.
 *
 * `invoiced` and `collected` are RANGED — what was billed and what came in
 * between the dates. `outstanding` and `overdue` are AS-AT — what is owed
 * right now, whenever it was billed. Ageing is always as at today.
 */
export interface ReceivablesSummaryProps {
    from: string;
    to: string;
    as_of: string;
    invoiced_centavos: number;
    collected_centavos: number;
    outstanding_centavos: number;
    overdue_centavos: number;
    aging: AgingBucket[];
    statuses: InvoiceStatusSlice[];
    monthly: CollectionsPoint[];
    top_outstanding: OutstandingPayerRow[];
}

export interface InvoiceDashboardPageProps {
    filters: {
        preset: DashboardPreset;
        from: string;
        to: string;
    };
    summary: ReceivablesSummaryProps;
}
