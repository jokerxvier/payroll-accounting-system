import { act, fireEvent, render, screen } from '@testing-library/react';
import { createRef } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { PaymentForm } from '@/components/admin/payment-form';
import type { PaymentFormHandle } from '@/components/admin/payment-form';
import type {
    CashAccountOption,
    OutstandingInvoice,
    PaymentContactOption,
} from '@/types';

const post = vi.fn();
const put = vi.fn();
const routerGet = vi.fn();

// Minimal useForm shim covering the surface the form uses. The assertions
// here are about the allocation grid and the running unallocated figure, not
// submission.
vi.mock('@inertiajs/react', async () => {
    const { useState: useStateInner } = await import('react');

    return {
        Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        router: { get: (...args: unknown[]) => routerGet(...args) },
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setData] = useStateInner<T>(initial);

            const setField = (
                key: keyof T | Partial<T> | ((previous: T) => T),
                value?: T[keyof T],
            ): void => {
                if (typeof key === 'function') {
                    // Inertia's useForm accepts a functional updater and the
                    // demo filler uses one. Without this branch the shim
                    // spreads the function itself into state and every field
                    // it set is silently discarded.
                    setData((prev) => (key as (previous: T) => T)(prev));
                } else if (typeof key === 'string') {
                    setData((prev) => ({ ...prev, [key]: value }));
                } else {
                    setData((prev) => ({ ...prev, ...(key as Partial<T>) }));
                }
            };

            return {
                data,
                errors: {} as Record<string, string>,
                processing: false,
                isDirty: true,
                setData: setField,
                post,
                put,
                reset: vi.fn(),
                clearErrors: vi.fn(),
                setDefaults: vi.fn(),
            };
        },
    };
});

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const CONTACTS: PaymentContactOption[] = [
    { id: 1, name: 'Dela Cruz Family', tin: null },
];

const CASH_ACCOUNTS: CashAccountOption[] = [
    {
        id: 10,
        code: '1100',
        name: 'Cash on Hand',
        type: 'asset',
        normal_balance: 'debit',
    },
];

/** Oldest first, the order the server hands them back in. */
const OUTSTANDING: OutstandingInvoice[] = [
    {
        id: 101,
        number: 'SI-000001',
        issue_date: '2026-07-01',
        due_date: '2026-07-31',
        total_centavos: 300_000,
        amount_paid_centavos: 0,
        balance_due_centavos: 300_000,
    },
    {
        id: 102,
        number: 'SI-000002',
        issue_date: '2026-08-01',
        due_date: '2026-08-31',
        total_centavos: 500_000,
        amount_paid_centavos: 100_000,
        balance_due_centavos: 400_000,
    },
];

function renderForm(outstanding = OUTSTANDING): void {
    render(
        <PaymentForm
            mode={{ kind: 'create', type: 'receipt' }}
            contactOptions={CONTACTS}
            cashAccountOptions={CASH_ACCOUNTS}
            outstandingInvoices={outstanding}
        />,
    );
}

/**
 * Render with a counterparty already chosen.
 *
 * This used edit mode because picking one through a Radix Select was not
 * driveable in jsdom. The field is a combobox now and `chooseContact()` below
 * drives it directly, but edit mode is still the shortest way to start a test
 * from a chosen payer without a round trip.
 */
function renderWithContact(outstanding = OUTSTANDING): void {
    render(
        <PaymentForm
            mode={{
                kind: 'edit',
                payment: {
                    id: 7,
                    type: 'receipt',
                    contact_id: 1,
                    payment_date: '2026-08-15',
                    amount_centavos: 0,
                    cash_account_id: 10,
                    method: 'cash',
                    reference: null,
                    notes: null,
                    allocations: [],
                },
            }}
            contactOptions={CONTACTS}
            cashAccountOptions={CASH_ACCOUNTS}
            outstandingInvoices={outstanding}
        />,
    );
}

/** Pick a payer the way an operator does, through the combobox. */
function chooseContact(name: string | RegExp = /Dela Cruz/): void {
    fireEvent.click(screen.getByLabelText('Received from'));
    fireEvent.click(screen.getByRole('option', { name }));
}

/**
 * Type character by character, the way a person does.
 *
 * `fireEvent.change` with a whole string at once round-trips fine even
 * through a badly controlled input, which is how the reformat-on-every-
 * keystroke bug shipped past the first version of the journal form's tests.
 */
function typeChars(label: string, chars: string): void {
    const input = screen.getByLabelText(label) as HTMLInputElement;

    for (const char of chars) {
        fireEvent.change(input, { target: { value: input.value + char } });
    }
}

function valueOf(label: string): string {
    return (screen.getByLabelText(label) as HTMLInputElement).value;
}

/** The value shown against a totals row. Scoped to <dt>. */
function totalFor(label: string): string {
    const term = screen
        .getAllByText(label)
        .find((node) => node.tagName === 'DT');

    return term?.parentElement?.querySelector('dd')?.textContent ?? '';
}

const APPLY_1 = 'Apply to SI-000001';
const APPLY_2 = 'Apply to SI-000002';

describe('PaymentForm', () => {
    it('lists what the counterparty still owes, oldest first', () => {
        renderForm();

        expect(screen.getByLabelText(APPLY_1)).toBeInTheDocument();
        expect(screen.getByLabelText(APPLY_2)).toBeInTheDocument();
    });

    it('prompts for a counterparty before showing any documents', () => {
        renderForm();

        expect(
            screen.getByText(/Choose a customer to see what they have/),
        ).toBeInTheDocument();
    });

    it('says there is nothing outstanding when the contact owes nothing', () => {
        renderWithContact([]);

        expect(
            screen.getByText(/whole receipt will be held as an advance/),
        ).toBeInTheDocument();
    });

    /* ── The typing bug this file exists to catch ───────────────────── */

    it('accepts a multi-digit total typed one key at a time', () => {
        renderForm();

        typeChars('Amount', '10000');

        expect(valueOf('Amount')).toBe('10000');
    });

    it('accepts a decimal allocation typed one key at a time', () => {
        renderForm();

        typeChars(APPLY_1, '1234.56');

        expect(valueOf(APPLY_1)).toBe('1234.56');
    });

    it('refuses a third decimal place rather than truncating it', () => {
        renderForm();

        typeChars('Amount', '10.999');

        expect(valueOf('Amount')).toBe('10.99');
    });

    it('keeps each allocation independent', () => {
        // Keyed by invoice id, not row index, so one row's text can never
        // leak into another's.
        renderForm();

        typeChars(APPLY_1, '100');
        typeChars(APPLY_2, '200');

        expect(valueOf(APPLY_1)).toBe('100');
        expect(valueOf(APPLY_2)).toBe('200');
    });

    /* ── The running unallocated figure ─────────────────────────────── */

    it('shows the whole amount as an advance before anything is applied', () => {
        renderForm();

        typeChars('Amount', '5000');

        expect(totalFor('Received')).toContain('5,000.00');
        expect(totalFor('Applied')).toContain('0.00');
        expect(totalFor('Held as an advance')).toContain('5,000.00');
    });

    it('reduces the advance as amounts are applied', () => {
        renderForm();

        typeChars('Amount', '5000');
        typeChars(APPLY_1, '3000');

        expect(totalFor('Applied')).toContain('3,000.00');
        expect(totalFor('Held as an advance')).toContain('2,000.00');
    });

    it('reports an over-application rather than a negative advance', () => {
        renderForm();

        typeChars('Amount', '1000');
        typeChars(APPLY_1, '3000');

        expect(totalFor('Over-applied by')).toContain('2,000.00');
        expect(
            screen.getByText(/More has been applied than this receipt carries/),
        ).toBeInTheDocument();
    });

    it('blocks submission while over-applied', () => {
        // The server refuses it anyway; stopping here saves a round trip and
        // says why in place.
        renderForm();

        typeChars('Amount', '1000');
        typeChars(APPLY_1, '3000');

        expect(
            screen.getByRole('button', { name: /Save draft receipt/ }),
        ).toBeDisabled();
    });

    it('allows submission when the amount covers what is applied', () => {
        renderForm();

        typeChars('Amount', '5000');
        typeChars(APPLY_1, '3000');

        expect(
            screen.getByRole('button', { name: /Save draft receipt/ }),
        ).toBeEnabled();
    });

    /* ── Allocate oldest first ──────────────────────────────────────── */

    it('spreads the amount across documents oldest first', () => {
        renderForm();

        typeChars('Amount', '5000');
        fireEvent.click(
            screen.getByRole('button', { name: 'Allocate oldest first' }),
        );

        // ₱5,000 covers the older ₱3,000 in full, leaving ₱2,000 for the next.
        expect(valueOf(APPLY_1)).toBe('3,000.00');
        expect(valueOf(APPLY_2)).toBe('2,000.00');
        expect(totalFor('Held as an advance')).toContain('0.00');
    });

    it('stops once the money runs out', () => {
        renderForm();

        typeChars('Amount', '1000');
        fireEvent.click(
            screen.getByRole('button', { name: 'Allocate oldest first' }),
        );

        expect(valueOf(APPLY_1)).toBe('1,000.00');
        expect(valueOf(APPLY_2)).toBe('');
    });

    it('never allocates more than a document owes', () => {
        // The second document owes ₱4,000 of its ₱5,000, so a ₱9,000 payment
        // leaves ₱2,000 over rather than over-applying it.
        renderForm();

        typeChars('Amount', '9000');
        fireEvent.click(
            screen.getByRole('button', { name: 'Allocate oldest first' }),
        );

        expect(valueOf(APPLY_1)).toBe('3,000.00');
        expect(valueOf(APPLY_2)).toBe('4,000.00');
        expect(totalFor('Held as an advance')).toContain('2,000.00');
    });

    it('clears every allocation', () => {
        renderForm();

        typeChars('Amount', '5000');
        fireEvent.click(
            screen.getByRole('button', { name: 'Allocate oldest first' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Clear' }));

        expect(valueOf(APPLY_1)).toBe('');
        expect(valueOf(APPLY_2)).toBe('');
        expect(totalFor('Applied')).toContain('0.00');
    });

    it('drops an allocation when its box is emptied', () => {
        renderForm();

        typeChars('Amount', '5000');
        typeChars(APPLY_1, '3000');
        expect(totalFor('Applied')).toContain('3,000.00');

        fireEvent.change(screen.getByLabelText(APPLY_1), {
            target: { value: '' },
        });

        expect(totalFor('Applied')).toContain('0.00');
    });
});

/*
 * Choosing a payer is what fetches their open documents. The field became a
 * searchable combobox because a school's register runs to hundreds of
 * families; the reload behind it is the part most easily lost in that swap.
 */
describe('choosing who paid', () => {
    it('searches the register rather than listing it', () => {
        renderForm();

        fireEvent.click(screen.getByLabelText('Received from'));
        fireEvent.change(
            screen.getByPlaceholderText(/search by name or tin/i),
            { target: { value: 'dela' } },
        );

        expect(
            screen.getByRole('option', { name: /Dela Cruz/ }),
        ).toBeInTheDocument();
    });

    it("asks the server for that payer's open documents", () => {
        // A partial reload, not a page load: the grid needs this contact's
        // documents and nothing else on the page has changed.
        routerGet.mockClear();
        renderForm();

        chooseContact();

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet.mock.calls[0][1]).toEqual({
            type: 'receipt',
            contact_id: 1,
        });
        expect(routerGet.mock.calls[0][2]).toMatchObject({
            only: ['outstandingInvoices'],
            preserveState: true,
        });
    });

    it('says who was chosen once one is', () => {
        renderForm();

        chooseContact();

        expect(screen.getByLabelText('Received from')).toHaveTextContent(
            'Dela Cruz Family',
        );
    });
});

/*
 * The dev filler. Gated server-side on `dev.demo-fill`; the form only exposes
 * the handle, and the page decides whether to offer a button for it.
 */
describe('filling with demo data', () => {
    function fill(
        props: {
            contactOptions?: typeof CONTACTS;
            cashAccountOptions?: typeof CASH_ACCOUNTS;
        } = {},
    ): React.RefObject<PaymentFormHandle | null> {
        const ref = createRef<PaymentFormHandle>();

        render(
            <PaymentForm
                ref={ref}
                mode={{ kind: 'create', type: 'receipt' }}
                contactOptions={props.contactOptions ?? CONTACTS}
                cashAccountOptions={props.cashAccountOptions ?? CASH_ACCOUNTS}
                outstandingInvoices={[]}
            />,
        );

        act(() => ref.current?.fillWithDemoData());

        return ref;
    }

    it('fills the amount box, not just the figure behind it', () => {
        // The amount input is driven by its own raw text. Setting the centavos
        // alone leaves the box blank while the totals below show money.
        fill();

        expect(valueOf('Amount')).not.toBe('');
        expect(Number(valueOf('Amount').replace(/,/g, ''))).toBeGreaterThan(0);
    });

    it('picks a real payer and asks for their documents', () => {
        // Through the same handler a click goes through — a filler that set
        // the id directly would leave the allocation grid empty.
        routerGet.mockClear();
        fill();

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet.mock.calls[0][1]).toMatchObject({ type: 'receipt' });
    });

    it('picks a cash account the server will accept', () => {
        // Never an invented id: PaymentRequest refuses anything that is not an
        // active, cash-equivalent asset, and the options list is already that.
        fill();

        expect(screen.getByLabelText('Received into')).toHaveTextContent(
            'Cash on Hand',
        );
    });

    it('leaves allocations alone', () => {
        // The open documents arrive a round trip after the payer is chosen, so
        // a filler that guessed them would be filling a grid it cannot see.
        fill();

        expect(screen.queryByLabelText(/^Apply to /)).not.toBeInTheDocument();
    });

    it('does nothing when there is nowhere for the money to sit', () => {
        // A half-filled form is worse than an untouched one.
        fill({ cashAccountOptions: [] });

        expect(valueOf('Amount')).toBe('');
    });

    it('does nothing when the register is empty', () => {
        fill({ contactOptions: [] });

        expect(valueOf('Amount')).toBe('');
    });
});
