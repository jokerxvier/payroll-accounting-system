/**
 * Phase 5 Slice 9 — the cutover snapshot import.
 *
 * Mirrors the parsed-row struct `App\Imports\OpeningBalanceImport` returns
 * and the summary `OpeningBalanceController::summarise()` computes. The
 * summary is server-side on purpose: the difference the page shows and the
 * difference the posting action checks have to come from the same
 * arithmetic.
 */

export interface OpeningBalanceRow {
    row_number: number;
    account_code: string | null;
    account_id: number | null;
    account_name: string | null;
    account_type: string | null;
    debit_centavos: number;
    credit_centavos: number;
    errors: string[];
}

export interface OpeningBalanceSummary {
    total_debit_centavos: number;
    total_credit_centavos: number;
    /** Debits minus credits. Zero is the only postable state. */
    difference_centavos: number;
    row_count: number;
    error_count: number;
    /** Whether an open accounting period covers the cutover date. */
    period_is_open: boolean;
}

export interface OpeningBalanceSnapshot {
    id: number;
    entry_number: string | null;
    date: string;
}
