/**
 * Phase 5 Slice 5 — invoice types.
 *
 * Amounts are integer centavos on the wire, matching the rest of the app.
 * The client converts to and from pesos at the input boundary only.
 *
 * One set of types covers both a sales invoice and a purchase bill, because
 * the shape is identical — only the posting direction and the numbering
 * series differ. The `type` discriminator says which.
 */

import type { Paginator } from './pagination';

export type InvoiceType = 'sales' | 'purchase';

export type InvoiceStatus =
    | 'draft'
    | 'approved'
    | 'sent'
    | 'partially_paid'
    | 'paid'
    | 'voided';

/** Lean contact shape for the counterparty picker. */
export interface InvoiceContactOption {
    id: number;
    name: string;
    tin: string | null;
}

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
    can: InvoiceRow['can'] & { print: boolean };
}

/** Shape the edit page hands to the form. */
export interface InvoiceEditable {
    id: number;
    type: InvoiceType;
    contact_id: number;
    reference: string | null;
    issue_date: string;
    due_date: string | null;
    is_vat_inclusive: boolean;
    notes: string | null;
    terms: string | null;
    lines: InvoiceLineDraft[];
}

/** Everything the create and edit forms need to populate their selects. */
export interface InvoiceFormOptions {
    contactOptions: InvoiceContactOption[];
    accountOptions: InvoiceAccountOption[];
    taxRateOptions: InvoiceTaxRateOption[];
    /**
     * A preview of the serial this document would take, or null when no
     * series is configured. A peek, never an allocation — most drafts are
     * opened and closed without ever being approved.
     */
    nextNumber: string | null;
}

export interface InvoiceIndexProps {
    invoices: Paginator<InvoiceRow>;
    filters: { type: InvoiceType; status: InvoiceStatus | null };
    can: { create: boolean };
}

/* ── Document numbering series ──────────────────────────────────────── */

export type DocumentSeriesType =
    | 'sales_invoice'
    | 'official_receipt'
    | 'credit_note'
    | 'bill';

export interface DocumentSeriesRow {
    id: number;
    document_type: DocumentSeriesType;
    label: string;
    prefix: string | null;
    padding: number;
    next_number: number;
    /** What the next document would actually be stamped with. */
    next_formatted: string;
    serial_start: number | null;
    serial_end: number | null;
    atp_number: string | null;
    permit_issued_at: string | null;
    /** False until the client supplies permit details — a normal state. */
    has_authority_to_print: boolean;
    /** Null when the series is unbounded. */
    remaining_in_range: number | null;
    is_active: boolean;
    can: { update: boolean };
}

export interface DocumentSeriesIndexProps {
    series: DocumentSeriesRow[];
    documentTypes: DocumentSeriesType[];
    can: { create: boolean };
}
