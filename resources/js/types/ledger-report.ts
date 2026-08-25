/**
 * Phase 5 Slice 8a — shapes for the three reports that read the ledger
 * directly.
 *
 * Every money field is integer centavos, matching the backend's `Money`
 * value object. Two signings appear and they are not interchangeable:
 *
 *  - `*_debit_centavos` / `*_credit_centavos` are unsigned column figures.
 *    Exactly one of a pair is non-zero; the other is 0 and prints as blank.
 *  - `closing_natural_centavos` and `running_raw_centavos` are signed.
 *    "Natural" means the account's own direction, so a liability in credit
 *    is positive. "Raw" means debits less credits, which is what makes a
 *    running balance column add up.
 */

export interface TrialBalanceRow {
    account_id: number;
    code: string;
    name: string;
    type: string;
    normal_balance: 'debit' | 'credit';
    opening_debit_centavos: number;
    opening_credit_centavos: number;
    period_debit_centavos: number;
    period_credit_centavos: number;
    closing_debit_centavos: number;
    closing_credit_centavos: number;
    closing_natural_centavos: number;
}

export interface TrialBalanceTotals {
    opening_debit_centavos: number;
    opening_credit_centavos: number;
    period_debit_centavos: number;
    period_credit_centavos: number;
    closing_debit_centavos: number;
    closing_credit_centavos: number;
    is_balanced: boolean;
    closing_variance_centavos: number;
}

export interface AccountLedgerLine {
    line_id: number;
    entry_id: number;
    entry_number: string | null;
    date: string;
    reference: string | null;
    narration: string | null;
    description: string | null;
    debit_centavos: number;
    credit_centavos: number;
    running_raw_centavos: number;
    contra_accounts: string[];
    is_reversal: boolean;
}

export interface AccountLedger {
    account: {
        id: number;
        code: string;
        name: string;
        type: string;
        normal_balance: 'debit' | 'credit';
    };
    opening_raw_centavos: number;
    closing_raw_centavos: number;
    closing_natural_centavos: number;
    total_debit_centavos: number;
    total_credit_centavos: number;
    lines: AccountLedgerLine[];
}

export interface LedgerAccountOption {
    id: number;
    code: string;
    name: string;
    type: string;
    is_active: boolean;
}

export interface JournalReportLine {
    id: number;
    account_code: string | null;
    account_name: string | null;
    description: string | null;
    debit_centavos: number;
    credit_centavos: number;
}

export interface JournalReportEntry {
    id: number;
    entry_number: string | null;
    date: string;
    reference: string | null;
    narration: string | null;
    source_type: string | null;
    is_reversal: boolean;
    total_debit_centavos: number;
    total_credit_centavos: number;
    lines: JournalReportLine[];
}
