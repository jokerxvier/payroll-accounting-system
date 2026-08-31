/**
 * Phase 5 Slice 4 — contact register types.
 *
 * A contact is who an invoice or bill is addressed to. One record with two
 * flags rather than separate customer and supplier shapes, because plenty of
 * counterparties are both.
 */

import type { Paginator } from './pagination';

/**
 * A contact as the counterparty picker sees it.
 *
 * Lives here rather than in `invoice.ts` because three forms choose a contact
 * — an invoice, a payment and a recurring schedule — and a payments component
 * should not have to name the invoice module to describe its own payer. The
 * TIN travels with the name: an operator holding a BIR document has the TIN,
 * not the spelling.
 */
export interface ContactPickerOption {
    id: number;
    name: string;
    tin: string | null;
}

/** Lean account shape for the control-account pickers. */
export interface ContactAccountOption {
    id: number;
    code: string;
    name: string;
    type: string;
}

export interface ContactRow {
    id: number;
    code: string;
    name: string;
    is_customer: boolean;
    is_supplier: boolean;
    /** Digits only — the server strips punctuation before storing. */
    tin: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    /**
     * Control-account overrides. Null means "use this school's AR_CONTROL /
     * AP_CONTROL system account", which is how posting resolves it.
     */
    receivable_account_id: number | null;
    payable_account_id: number | null;
    receivable_account: Pick<
        ContactAccountOption,
        'id' | 'code' | 'name'
    > | null;
    payable_account: Pick<ContactAccountOption, 'id' | 'code' | 'name'> | null;
    /**
     * Reserved pointer at an LMS student. Nothing populates it — reading LMS
     * student tables is outside the contract in PLAN.md §2.
     */
    lms_student_id: number | null;
    is_active: boolean;
    notes: string | null;
    can: {
        update: boolean;
        delete: boolean;
    };
}

export type ContactRoleFilter = 'customer' | 'supplier';

export interface ContactIndexProps {
    contacts: Paginator<ContactRow>;
    filters: {
        search: string | null;
        role: ContactRoleFilter | null;
    };
    receivableAccountOptions: ContactAccountOption[];
    payableAccountOptions: ContactAccountOption[];
    can: { create: boolean };
}
