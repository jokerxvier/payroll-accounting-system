/**
 * Phase 5 Slice 5 — invoice types.
 *
 * Amounts are integer centavos on the wire, matching the rest of the app.
 * The client converts to and from pesos at the input boundary only.
 *
 * One set of types covers both a sales invoice and a purchase bill, because
 * the shape is identical — only the posting direction differs. The `type`
 * discriminator says which.
 */

import type { ContactAccountOption, ContactPickerOption } from './contact';
import type { Paginator } from './pagination';

export type InvoiceType = 'sales' | 'purchase';

export type InvoiceStatus =
    | 'draft'
    | 'approved'
    | 'sent'
    | 'partially_paid'
    | 'paid'
    | 'voided';

/**
 * Lean contact shape for the counterparty picker.
 *
 * An alias rather than a second declaration: the picker is shared with the
 * payment and recurring-schedule forms now, so there is one shape, and this
 * name survives only so the invoice module reads in its own vocabulary.
 */
export type InvoiceContactOption = ContactPickerOption;

/** Lean account shape for the line pickers — income or expense only. */
export interface InvoiceAccountOption {
    id: number;
    code: string;
    name: string;
    type: string;
    normal_balance: 'debit' | 'credit';
}

export interface InvoiceTaxRateOption {
    id: number;
    code: string;
    name: string;
    /** Basis points: 12% is 1200. */
    rate_bps: number;
    type: 'vat_sales' | 'vat_purchase' | 'exempt' | 'zero_rated';
}

/** A line as it exists in the editor, before the server assigns it an id. */
export interface InvoiceLineDraft {
    description: string;
    /** Decimal string with up to 4 places, kept as text so it never becomes a float. */
    quantity: string;
    unit_price_centavos: number;
    account_id: number | null;
    tax_rate_id: number | null;
}

/** A persisted line, as returned by the detail endpoint. */
export interface InvoiceLineRow {
    id: number;
    line_number: number;
    description: string;
    quantity: string;
    unit_price_centavos: number;
    account_code: string | null;
    account_name: string | null;
    tax_rate_code: string | null;
    tax_rate_label: string | null;
    line_net_centavos: number;
    line_tax_centavos: number;
}

/** Row shape on the invoice list. */
export interface InvoiceRow {
    id: number;
    type: InvoiceType;
    /** Null until approved — a draft does not burn a BIR serial. */
    number: string | null;
    /** Raised by a recurring schedule rather than typed by hand. */
    is_recurring: boolean;
    reference: string | null;
    contact_name: string | null;
    issue_date: string;
    due_date: string | null;
    status: InvoiceStatus;
    total_centavos: number;
    amount_paid_centavos: number;
    balance_due_centavos: number;
    /**
     * Per-row policy results, so the list renders only the actions that are
     * legal for this document. Edit and delete are drafts-only; an issued
     * document is corrected by voiding it and raising a replacement.
     */
    can: {
        update: boolean;
        delete: boolean;
        approve: boolean;
        void: boolean;
    };
}

export interface InvoiceDetail extends InvoiceRow {
    is_vat_inclusive: boolean;
    /** The three BIR sales buckets, reported separately on the face. */
    vatable_sales_centavos: number;
    vat_exempt_sales_centavos: number;
    zero_rated_sales_centavos: number;
    vat_centavos: number;
    notes: string | null;
    terms: string | null;
    approved_at: string | null;
    /** When it was last emailed, and to where. Null until someone sends it. */
    sent_at: string | null;
    sent_to: string | null;
    /**
     * The customer-facing pay link, or null until one has been minted.
     *
     * Tokens are created on demand, so this is null for every invoice nobody
     * has shared — which keeps the number of live public URLs equal to the
     * number someone deliberately created.
     */
    pay_url: string | null;
    voided_at: string | null;
    void_reason: string | null;
    contact: {
        id: number;
        name: string;
        tin: string | null;
        email: string | null;
        address: string | null;
    } | null;
    journal_entry: {
        id: number;
        entry_number: string | null;
        status: string;
    } | null;
    lines: InvoiceLineRow[];
    /**
     * Posted payments applied to this document. Drafts are excluded — one
     * settles nothing, and listing it would imply money that has not
     * arrived.
     */
    payments: Array<{
        id: number;
        payment_id: number;
        reference: string | null;
        payment_date: string | null;
        amount_centavos: number;
    }>;
    can: InvoiceRow['can'] & { print: boolean; send: boolean };
}

/** Shape the edit page hands to the form. */
export interface InvoiceEditable {
    id: number;
    type: InvoiceType;
    contact_id: number;
    /** Who the charges are for. Null on an invoice raised without a student. */
    lms_student_id: number | null;
    reference: string | null;
    issue_date: string;
    due_date: string | null;
    is_vat_inclusive: boolean;
    notes: string | null;
    terms: string | null;
    lines: InvoiceLineDraft[];
}

/** A payer recorded as responsible for a student. */
export interface StudentPayerOption {
    contact_id: number;
    name: string | null;
    tin: string | null;
    address: string | null;
    relationship: string | null;
    is_primary_payer: boolean;
}

/**
 * A student someone is recorded as paying for.
 *
 * `payers` is ordered primary-first, so the form can take the head of the list
 * rather than re-deciding what "primary" means on the client.
 */
export interface InvoiceStudentOption {
    lms_student_id: number;
    name: string;
    payers: StudentPayerOption[];
}

/** Everything the create and edit forms need to populate their selects. */
export interface InvoiceFormOptions {
    contactOptions: InvoiceContactOption[];
    /**
     * Whether the counterparty picker offers a New button. Mirrors the
     * `create` ability on Contact — an operator who cannot reach the contacts
     * register must not be handed a sheet that posts to it.
     */
    canCreateContact?: boolean;
    /**
     * Control-account overrides for the new-contact sheet, which is the same
     * component the contacts register uses. Absent on a page that does not
     * offer the New button.
     */
    receivableAccountOptions?: ContactAccountOption[];
    payableAccountOptions?: ContactAccountOption[];
    accountOptions: InvoiceAccountOption[];
    taxRateOptions: InvoiceTaxRateOption[];
    /** Sales only — a supplier's bill has no pupil behind it. */
    studentOptions?: InvoiceStudentOption[];
    /**
     * Whether to offer the demo-fill button. Super-admin outside production
     * only, via the `dev.demo-fill` gate — it is a development affordance,
     * not a product feature.
     */
    canDemoFill?: boolean;
}

export interface InvoiceIndexProps {
    invoices: Paginator<InvoiceRow>;
    filters: {
        type: InvoiceType;
        /** Free text over number, reference, student name, notes and payer. */
        search: string | null;
        /** Set when arriving from the dashboard's Top Outstanding table. */
        contact_id: number | null;
        status: InvoiceStatus | null;
        /** Inclusive issue-date bounds, 'YYYY-MM-DD' or null. */
        from: string | null;
        to: string | null;
    };
    can: { create: boolean };
}
