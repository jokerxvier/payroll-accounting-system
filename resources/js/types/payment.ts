/**
 * Phase 5 Slice 7 — payment types.
 *
 * Amounts are integer centavos on the wire, matching the rest of the app.
 * The client converts to and from pesos at the input boundary only.
 *
 * One set of types covers a receipt and a disbursement, because the shape is
 * identical — only the posting direction and which documents can be settled
 * differ. The `type` discriminator says which.
 */

import type { ContactPickerOption } from './contact';
import type { Paginator } from './pagination';

export type PaymentType = 'receipt' | 'disbursement';

export type PaymentStatus = 'draft' | 'posted' | 'voided';

export type PaymentMethod =
    | 'cash'
    | 'cheque'
    | 'bank_transfer'
    | 'online'
    | 'other';

/** Lean contact shape for the counterparty picker. Shared — see `contact.ts`. */
export type PaymentContactOption = ContactPickerOption;

/** Asset accounts money can actually move through. Control accounts excluded. */
export interface CashAccountOption {
    id: number;
    code: string;
    name: string;
    type: string;
    normal_balance: 'debit' | 'credit';
}

/** A document with something still owing on it, for the allocation grid. */
export interface OutstandingInvoice {
    id: number;
    number: string | null;
    issue_date: string;
    due_date: string | null;
    total_centavos: number;
    amount_paid_centavos: number;
    balance_due_centavos: number;
}

/** An allocation as it exists in the editor. */
export interface PaymentAllocationDraft {
    invoice_id: number;
    amount_centavos: number;
}

/** A persisted allocation, as returned by the detail endpoint. */
export interface PaymentAllocationRow {
    id: number;
    invoice_id: number;
    invoice_number: string | null;
    invoice_status: string | null;
    invoice_total_centavos: number | null;
    amount_centavos: number;
}

export interface PaymentRow {
    id: number;
    type: PaymentType;
    contact_name: string | null;
    payment_date: string;
    amount_centavos: number;
    allocated_centavos: number;
    /** What no document has claimed — posts to advances, not to the control account. */
    unallocated_centavos: number;
    method: PaymentMethod;
    reference: string | null;
    cash_account_name: string | null;
    status: PaymentStatus;
    /**
     * The ledger entry this payment wrote, or null until it is posted.
     *
     * Null is the honest answer for a draft — it has written nothing to the
     * books yet — so the list shows a dash rather than an empty cell that
     * looks like missing data.
     */
    journal_entry: { id: number; entry_number: string | null } | null;
    /**
     * Per-row policy results, so the list renders only the actions that are
     * legal for this payment. Edit and delete are drafts-only; a posted
     * payment is undone by voiding.
     */
    can: {
        update: boolean;
        delete: boolean;
        post: boolean;
        void: boolean;
    };
}

export interface PaymentDetail extends PaymentRow {
    notes: string | null;
    posted_at: string | null;
    voided_at: string | null;
    void_reason: string | null;
    contact: {
        id: number;
        name: string;
        tin: string | null;
        email: string | null;
    } | null;
    journal_entry: {
        id: number;
        entry_number: string | null;
        status: string;
    } | null;
    allocations: PaymentAllocationRow[];
}

/** Shape the edit page hands to the form. */
export interface PaymentEditable {
    id: number;
    type: PaymentType;
    contact_id: number;
    payment_date: string;
    amount_centavos: number;
    cash_account_id: number;
    method: PaymentMethod;
    reference: string | null;
    notes: string | null;
    allocations: PaymentAllocationDraft[];
}

export interface PaymentFormOptions {
    contactOptions: PaymentContactOption[];
    cashAccountOptions: CashAccountOption[];
    /**
     * Scoped to the chosen contact. Empty on create until one is picked —
     * loading every open document in the school would be a large payload
     * nobody looks at.
     */
    outstandingInvoices: OutstandingInvoice[];
    /**
     * Whether to offer the demo-fill button. Super-admin outside production
     * only, via the `dev.demo-fill` gate — a development affordance, not a
     * product feature.
     */
    canDemoFill?: boolean;
}

export interface PaymentIndexProps {
    payments: Paginator<PaymentRow>;
    filters: {
        type: PaymentType;
        /** Free text over reference, gateway reference, notes and payer name. */
        search: string | null;
        status: PaymentStatus | null;
        /** Inclusive payment-date bounds, 'YYYY-MM-DD' or null. */
        from: string | null;
        to: string | null;
    };
    can: { create: boolean };
}
