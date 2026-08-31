/**
 * Recurring invoice schedules — the standing instruction, not the document.
 *
 * Amounts are integer centavos on the wire, as everywhere else; the form
 * converts at the input boundary only, through `@/lib/money-input`.
 */

import type {
    InvoiceAccountOption,
    InvoiceContactOption,
    InvoiceTaxRateOption,
    InvoiceType,
} from './invoice';
import type { Paginator } from './pagination';

export type RecurringFrequency = 'monthly' | 'quarterly' | 'annually';

/** A template line. No net or tax: those are computed when an invoice is raised. */
export interface RecurringInvoiceLineDraft {
    description: string;
    /** Decimal string with up to 4 places, kept as text so it never becomes a float. */
    quantity: string;
    unit_price_centavos: number;
    account_id: number | null;
    tax_rate_id: number | null;
}

/** Row shape on the schedules list. */
export interface RecurringInvoiceRow {
    id: number;
    name: string;
    type: InvoiceType;
    contact_name: string | null;
    student_name: string | null;
    frequency: RecurringFrequency;
    day_of_month: number;
    next_run_on: string;
    ends_on: string | null;
    is_active: boolean;
    generated_count: number;
    last_generated_on: string | null;
    /**
     * Why the last run raised nothing for this schedule. Null when it is
     * healthy — a payer who stopped being a customer is the usual cause.
     */
    last_error: string | null;
    can: {
        update: boolean;
        delete: boolean;
        pause: boolean;
    };
}

/** Shape the edit page hands to the form. */
export interface RecurringInvoiceEditable {
    id: number;
    name: string;
    type: InvoiceType;
    contact_id: number;
    lms_student_id: number | null;
    reference: string | null;
    is_vat_inclusive: boolean;
    notes: string | null;
    terms: string | null;
    frequency: RecurringFrequency;
    day_of_month: number;
    starts_on: string;
    ends_on: string | null;
    due_days: number | null;
    is_active: boolean;
    lines: RecurringInvoiceLineDraft[];
}

/** Everything the create and edit forms need to populate their selects. */
export interface RecurringInvoiceFormOptions {
    contactOptions: InvoiceContactOption[];
    accountOptions: InvoiceAccountOption[];
    taxRateOptions: InvoiceTaxRateOption[];
}

export interface RecurringInvoiceIndexProps {
    schedules: Paginator<RecurringInvoiceRow>;
    filters: {
        status: 'active' | 'paused' | null;
    };
    can: { create: boolean };
}

export const RECURRING_FREQUENCY_LABELS: Record<RecurringFrequency, string> = {
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    annually: 'Annually',
};
