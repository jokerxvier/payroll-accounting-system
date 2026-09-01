/**
 * Phase 5 Slice 2 — journal types.
 *
 * Amounts are integer centavos on the wire, matching the rest of the app.
 * The client converts to and from pesos at the input boundary only.
 */

import type { Paginator } from './pagination';

export type JournalEntryStatus = 'draft' | 'pending' | 'posted' | 'voided';

/** Lean account shape for the line pickers. */
export interface JournalAccountOption {
    id: number;
    code: string;
    name: string;
    type: string;
    normal_balance: 'debit' | 'credit';
}

/** A line as it exists in the editor, before the server assigns it an id. */
export interface JournalEntryLineDraft {
    account_id: number | null;
    debit_centavos: number;
    credit_centavos: number;
    description: string;
}

/** A persisted line, as returned by the detail endpoint. */
export interface JournalEntryLineRow {
    id: number;
    line_number: number;
    account_id: number;
    account_code: string | null;
    account_name: string | null;
    debit_centavos: number;
    credit_centavos: number;
    description: string | null;
}

/** Row shape on the journal list. */
export interface JournalEntryRow {
    id: number;
    /** Null until the entry posts — drafts do not burn a number. */
    entry_number: string | null;
    date: string;
    reference: string | null;
    narration: string | null;
    status: JournalEntryStatus;
    period_code: string | null;
    total_debit_centavos: number;
    total_credit_centavos: number;
    /** True when a reversing entry has already been posted against this one. */
    has_been_reversed: boolean;
    /** True when this entry exists to reverse another. */
    is_reversal: boolean;
    /**
     * Per-row policy results, so the list renders only the actions that are
     * legal for this entry. Edit and delete are drafts-only; a posted entry
     * is corrected by posting a reversal.
     */
    can: {
        update: boolean;
        delete: boolean;
        reverse: boolean;
    };
}

export interface JournalEntryDetail extends JournalEntryRow {
    reversal_of_entry_id: number | null;
    posted_at: string | null;
    reversed_at: string | null;
    period_status: string | null;
    lines: JournalEntryLineRow[];
    /**
     * The detail page additionally offers Post, which the list deliberately
     * does not — posting is irreversible, so it keeps its confirmation step.
     */
    can: JournalEntryRow['can'] & { post: boolean };
}

/** Shape the edit page hands to the form. */
export interface JournalEntryEditable {
    id: number;
    date: string;
    reference: string | null;
    narration: string | null;
    lines: JournalEntryLineDraft[];
}

export interface JournalEntryIndexProps {
    entries: Paginator<JournalEntryRow>;
    filters: {
        /** Free text over entry number, reference and narration. */
        search: string | null;
        status: JournalEntryStatus | null;
        /** Inclusive entry-date bounds, 'YYYY-MM-DD' or null. */
        from: string | null;
        to: string | null;
    };
    can: { create: boolean };
}
