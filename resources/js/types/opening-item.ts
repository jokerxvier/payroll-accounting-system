/**
 * The open-items import — the documents behind the opening AR and AP.
 *
 * Money is integer centavos on the wire, as everywhere else; the page divides
 * by 100 once, at the point of display. Any change here must land in lockstep
 * with `app/Http/Controllers/Admin/Accounting/OpeningItemController.php`.
 */

/** One parsed worksheet row, with everything wrong about it. */
export interface OpeningItemRow {
    row_number: number;
    type: 'sales' | 'purchase' | null;
    contact_id: number | null;
    contact_name: string | null;
    number: string | null;
    issue_date: string | null;
    due_date: string | null;
    total_centavos: number;
    amount_paid_centavos: number;
    student_name: string | null;
    /** Worth knowing, but does not block the import. */
    warnings: string[];
    /** Any one of these refuses the whole file. */
    errors: string[];
}

export interface OpeningItemSummary {
    row_count: number;
    error_count: number;
    warning_count: number;
    total_centavos: number;
    already_paid_centavos: number;
    /** What the ageing report will show once these are recorded. */
    outstanding_centavos: number;
    books_are_open: boolean;
}

/**
 * One control account measured against the documents that explain it.
 *
 * `control_centavos` is what the cutover snapshot put into AR or AP;
 * `items_centavos` is what the open items add up to. A difference means the
 * school's previous system did not agree with itself — a finding, not a
 * blocker.
 */
export interface OpeningItemReconciliationRow {
    key: 'receivable' | 'payable';
    label: string;
    control_centavos: number;
    items_centavos: number;
    difference_centavos: number;
    is_reconciled: boolean;
}

export interface OpeningItemPageProps {
    parsed?: OpeningItemRow[] | null;
    token?: string | null;
    sourceFilename?: string | null;
    /** The cutover date, or null when the books were never opened. */
    booksOpenedOn?: string | null;
    summary?: OpeningItemSummary | null;
    reconciliation?: OpeningItemReconciliationRow[];
    recordedCount?: number;
}
