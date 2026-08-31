import { act, fireEvent, render, screen } from '@testing-library/react';
import { createRef } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { InvoiceForm } from '@/components/admin/invoice-form';
import type { InvoiceFormHandle } from '@/components/admin/invoice-form';
import type {
    InvoiceAccountOption,
    InvoiceContactOption,
    InvoiceStudentOption,
    InvoiceTaxRateOption,
} from '@/types';

const post = vi.fn();
const put = vi.fn();

// Minimal useForm shim covering the surface the form uses. The assertions
// here are about the totals preview and line mechanics, not submission.
vi.mock('@inertiajs/react', async () => {
    const { useState: useStateInner } = await import('react');

    return {
        Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setData] = useStateInner<T>(initial);

            const setField = (
                key: keyof T | Partial<T> | ((previous: T) => T),
                value?: T[keyof T],
            ): void => {
                if (typeof key === 'function') {
                    // Inertia's useForm accepts a functional updater. The
                    // shim used to fall through to the object branch and
                    // spread the function itself, which silently discarded
                    // every field a caller set that way.
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

const CONTACTS: InvoiceContactOption[] = [
    { id: 1, name: 'Dela Cruz Family', tin: null },
];

const ACCOUNTS: InvoiceAccountOption[] = [
    {
        id: 10,
        code: '4100',
        name: 'Tuition Fee Income',
        type: 'income',
        normal_balance: 'credit',
    },
];

const RATES: InvoiceTaxRateOption[] = [
    {
        id: 1,
        code: 'VAT_12_SALES',
        name: 'VAT 12%',
        rate_bps: 1200,
        type: 'vat_sales',
    },
    {
        id: 2,
        code: 'VAT_EXEMPT',
        name: 'VAT Exempt',
        rate_bps: 0,
        type: 'exempt',
    },
    {
        id: 3,
        code: 'VAT_ZERO',
        name: 'Zero-rated',
        rate_bps: 0,
        type: 'zero_rated',
    },
];

function renderForm(): void {
    render(
        <InvoiceForm
            mode={{ kind: 'create', type: 'sales' }}
            contactOptions={CONTACTS}
            accountOptions={ACCOUNTS}
            taxRateOptions={RATES}
        />,
    );
}

/**
 * Type character by character, the way a person does.
 *
 * `fireEvent.change` with a whole string at once round-trips fine even
 * through a badly controlled input, which is exactly how the
 * reformat-on-every-keystroke bug shipped past the first version of the
 * journal form's tests. Each character is appended to whatever the input is
 * currently displaying, so a value that snaps back mid-entry shows up here.
 */
function typeChars(label: string, chars: string): void {
    const input = screen.getByLabelText(label) as HTMLInputElement;

    for (const char of chars) {
        fireEvent.change(input, { target: { value: input.value + char } });
    }
}

function setValue(label: string, value: string): void {
    fireEvent.change(screen.getByLabelText(label), { target: { value } });
}

/**
 * The value shown against a totals row, e.g. "VAT".
 *
 * Scoped to <dt> deliberately: "VAT" is also a column header in the lines
 * table, and matching on text alone picks that up instead.
 */
function totalFor(label: string): string {
    const term = screen
        .getAllByText(label)
        .find((node) => node.tagName === 'DT');

    return term?.parentElement?.querySelector('dd')?.textContent ?? '';
}

describe('InvoiceForm', () => {
    it('starts with one line', () => {
        renderForm();

        expect(screen.getByLabelText('Line 1 description')).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Line 2 description'),
        ).not.toBeInTheDocument();
    });

    /* ── The typing bug this file exists to catch ───────────────────── */

    it('accepts a multi-digit price typed one key at a time', () => {
        // The regression that shipped on the journal form: a controlled
        // input derived from parsed centavos reformats on every keystroke,
        // so "10000" becomes unreachable.
        renderForm();

        typeChars('Line 1 unit price', '10000');

        expect(
            (screen.getByLabelText('Line 1 unit price') as HTMLInputElement)
                .value,
        ).toBe('10000');
    });

    it('accepts a decimal price typed one key at a time', () => {
        renderForm();

        typeChars('Line 1 unit price', '1234.56');

        expect(
            (screen.getByLabelText('Line 1 unit price') as HTMLInputElement)
                .value,
        ).toBe('1234.56');
    });

    it('refuses a third decimal place rather than accepting and truncating it', () => {
        renderForm();

        typeChars('Line 1 unit price', '10.999');

        expect(
            (screen.getByLabelText('Line 1 unit price') as HTMLInputElement)
                .value,
        ).toBe('10.99');
    });

    it('refuses letters in a price', () => {
        renderForm();

        typeChars('Line 1 unit price', '12a3');

        expect(
            (screen.getByLabelText('Line 1 unit price') as HTMLInputElement)
                .value,
        ).toBe('123');
    });

    it('accepts a fractional quantity typed one key at a time', () => {
        renderForm();

        setValue('Line 1 quantity', '');
        typeChars('Line 1 quantity', '2.5');

        expect(
            (screen.getByLabelText('Line 1 quantity') as HTMLInputElement)
                .value,
        ).toBe('2.5');
    });

    it('accepts a negative quantity for a discount line', () => {
        renderForm();

        setValue('Line 1 quantity', '');
        typeChars('Line 1 quantity', '-1');

        expect(
            (screen.getByLabelText('Line 1 quantity') as HTMLInputElement)
                .value,
        ).toBe('-1');
    });

    /* ── The totals preview ─────────────────────────────────────────── */

    it('shows an untaxed line in VAT-exempt sales', () => {
        // A line with no rate chosen yet posts no VAT, and the preview must
        // agree with the server rather than guessing at VATable.
        renderForm();

        typeChars('Line 1 unit price', '1000');

        expect(totalFor('VAT-exempt sales')).toContain('1,000.00');
        expect(totalFor('VAT')).toContain('0.00');
        expect(totalFor('Total')).toContain('1,000.00');
    });

    it('multiplies quantity by unit price', () => {
        renderForm();

        setValue('Line 1 quantity', '3');
        typeChars('Line 1 unit price', '250');

        expect(totalFor('Total')).toContain('750.00');
    });

    it('keeps the total equal to its parts', () => {
        renderForm();

        setValue('Line 1 quantity', '2.5');
        typeChars('Line 1 unit price', '400');

        expect(totalFor('VAT-exempt sales')).toContain('1,000.00');
        expect(totalFor('Total')).toContain('1,000.00');
    });

    /* ── Line mechanics ─────────────────────────────────────────────── */

    it('adds a line', () => {
        renderForm();

        fireEvent.click(screen.getByRole('button', { name: 'Add line' }));

        expect(screen.getByLabelText('Line 2 description')).toBeInTheDocument();
    });

    it('removes a line', () => {
        renderForm();

        fireEvent.click(screen.getByRole('button', { name: 'Add line' }));
        fireEvent.click(screen.getByLabelText('Remove line 2'));

        expect(
            screen.queryByLabelText('Line 2 description'),
        ).not.toBeInTheDocument();
    });

    it('empties the last line rather than leaving a form with none', () => {
        // An invoice needs at least one line, and a form with zero lines and
        // no way back would be a dead end.
        renderForm();

        typeChars('Line 1 unit price', '500');
        fireEvent.click(screen.getByLabelText('Remove line 1'));

        expect(screen.getByLabelText('Line 1 description')).toBeInTheDocument();
        expect(
            (screen.getByLabelText('Line 1 unit price') as HTMLInputElement)
                .value,
        ).toBe('');
    });

    it('keeps each line price independent when a line is removed', () => {
        renderForm();

        typeChars('Line 1 unit price', '100');
        fireEvent.click(screen.getByRole('button', { name: 'Add line' }));
        typeChars('Line 2 unit price', '200');

        fireEvent.click(screen.getByLabelText('Remove line 1'));

        // The surviving line keeps its own figure rather than inheriting the
        // removed line's raw text by index.
        expect(
            (screen.getByLabelText('Line 1 unit price') as HTMLInputElement)
                .value,
        ).toBe('200');
        expect(totalFor('Total')).toContain('200.00');
    });
});

describe('counterparty picker with nothing to choose', () => {
    function renderEmpty(type: 'sales' | 'purchase'): void {
        render(
            <InvoiceForm
                mode={{ kind: 'create', type }}
                contactOptions={[]}
                accountOptions={ACCOUNTS}
                taxRateOptions={RATES}
            />,
        );
    }

    it('says there are no customers rather than showing an empty picker', () => {
        renderEmpty('sales');

        expect(screen.getByText('No customers yet')).toBeInTheDocument();
        expect(screen.getByText(/to invoice them/i)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /go to contacts/i }),
        ).toBeInTheDocument();
    });

    it('names the supplier flag on a bill, not the customer one', () => {
        renderEmpty('purchase');

        expect(screen.getByText('No suppliers yet')).toBeInTheDocument();
        expect(screen.getByText(/to bill them/i)).toBeInTheDocument();
    });

    it('disables the picker so it cannot be opened onto nothing', () => {
        renderEmpty('sales');

        expect(screen.getByLabelText('Customer')).toBeDisabled();
    });

    it('stays out of the way once a contact exists', () => {
        renderForm();

        expect(screen.queryByText('No customers yet')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Customer')).not.toBeDisabled();
    });
});

describe('demo fill', () => {
    /*
     * The button itself lives in the page header beside Back, so what is
     * exercised here is the handle the page calls — the fill logic and the
     * form state it touches, which is the part that can break.
     */
    function renderWithHandle(): React.RefObject<InvoiceFormHandle | null> {
        const ref = createRef<InvoiceFormHandle>();

        render(
            <InvoiceForm
                ref={ref}
                mode={{ kind: 'create', type: 'sales' }}
                contactOptions={CONTACTS}
                accountOptions={ACCOUNTS}
                taxRateOptions={RATES}
            />,
        );

        return ref;
    }

    it('exposes a fill handle to the page', () => {
        const ref = renderWithHandle();

        expect(typeof ref.current?.fillWithDemoData).toBe('function');
    });

    it('fills the dates and at least one priced line', () => {
        const ref = renderWithHandle();

        act(() => ref.current?.fillWithDemoData());

        // The price input is driven by its own raw text, so a fill that set
        // only the centavos would leave this blank while the totals showed
        // figures — the bug this assertion exists to catch.
        const price = screen.getByLabelText(
            'Line 1 unit price',
        ) as HTMLInputElement;

        expect(price.value).not.toBe('');
        // Thousands separators stripped first: the box shows "15,000.00", and
        // `Number` of that is NaN.
        expect(Number(price.value.replace(/,/g, ''))).toBeGreaterThan(0);

        // Parsed rather than string-matched: "PHP 15,000.00" contains the
        // substring "0.00", so a `not.toContain` check here passes for the
        // wrong reason and fails for the right one.
        const total = Number((totalFor('Total') ?? '').replace(/[^\d.]/g, ''));
        expect(total).toBeGreaterThan(0);
    });

    it('composes only from the options it was given', () => {
        const ref = renderWithHandle();

        act(() => ref.current?.fillWithDemoData());

        // Whatever it picked has to be a real row for this tenant, or the
        // filled form would not submit.
        const names = CONTACTS.map((c) => c.name);
        expect(
            names.some(
                (name) => screen.queryAllByText(new RegExp(name)).length > 0,
            ),
        ).toBe(true);
    });

    it('produces a different draft on a second call', () => {
        const ref = renderWithHandle();

        act(() => ref.current?.fillWithDemoData());
        const first = (
            screen.getByLabelText('Line 1 unit price') as HTMLInputElement
        ).value;

        // Randomised, so this can legitimately repeat; ten rolls making the
        // same choice every time would mean it is not random at all.
        const seen = new Set([first]);

        for (let i = 0; i < 10; i++) {
            act(() => ref.current?.fillWithDemoData());
            seen.add(
                (screen.getByLabelText('Line 1 unit price') as HTMLInputElement)
                    .value,
            );
        }

        expect(seen.size).toBeGreaterThan(1);
    });
});

/*
 * The header asks for the customer first, and the Student field is an answer
 * to who that customer's charges are for — so it only exists when the customer
 * is somebody's parent or guardian. A contact with no recorded children is an
 * organisation being billed for facility hire; offering it a Student picker
 * reads as missing data rather than as a field that does not apply.
 */
describe('customer drives the student', () => {
    const PAYER_CONTACTS: InvoiceContactOption[] = [
        { id: 1, name: 'Dela Cruz Family', tin: null },
        { id: 2, name: 'Ana Reyes', tin: '123-456-789-000' },
        { id: 3, name: 'Barangay Malanday', tin: null },
    ];

    function payer(contactId: number) {
        return {
            contact_id: contactId,
            name: 'Payer',
            tin: null,
            address: null,
            relationship: 'Mother',
            is_primary_payer: true,
        };
    }

    const STUDENTS: InvoiceStudentOption[] = [
        {
            lms_student_id: 11,
            name: 'Juan Dela Cruz',
            payers: [payer(1)],
        },
        { lms_student_id: 21, name: 'Miguel Reyes', payers: [payer(2)] },
        { lms_student_id: 22, name: 'Sofia Reyes', payers: [payer(2)] },
    ];

    function renderPickers(): void {
        render(
            <InvoiceForm
                mode={{ kind: 'create', type: 'sales' }}
                contactOptions={PAYER_CONTACTS}
                accountOptions={ACCOUNTS}
                taxRateOptions={RATES}
                studentOptions={STUDENTS}
            />,
        );
    }

    function chooseCustomer(name: string | RegExp): void {
        fireEvent.click(screen.getByLabelText('Customer'));
        fireEvent.click(screen.getByRole('option', { name }));
    }

    it('asks for the customer before anything about a student', () => {
        renderPickers();

        expect(screen.queryByLabelText('Student')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Customer')).toHaveTextContent(
            'Choose a customer',
        );
    });

    it('reveals the student picker once a guardian is chosen', () => {
        renderPickers();

        chooseCustomer(/Ana Reyes/);

        expect(screen.getByLabelText('Student')).toBeInTheDocument();
        expect(
            screen.getByText(/recorded as a guardian of 2 students/i),
        ).toBeInTheDocument();
    });

    it('leaves the student picker out for a customer with no children', () => {
        renderPickers();

        chooseCustomer(/Barangay Malanday/);

        expect(screen.getByLabelText('Customer')).toHaveTextContent(
            'Barangay Malanday',
        );
        expect(screen.queryByLabelText('Student')).not.toBeInTheDocument();
    });

    it('loads the only child of a guardian who has one', () => {
        renderPickers();

        chooseCustomer(/Dela Cruz Family/);

        // Narrowing and auto-selection in one assertion: the hint only
        // appears for a student that belongs to the chosen payer.
        expect(
            screen.getByText('Charges are for Juan Dela Cruz.'),
        ).toBeInTheDocument();
    });

    it('does not guess between two children', () => {
        renderPickers();

        chooseCustomer(/Ana Reyes/);

        expect(screen.queryByText(/^Charges are for/)).not.toBeInTheDocument();
    });

    it("drops a student who is not the new customer's", () => {
        renderPickers();

        chooseCustomer(/Dela Cruz Family/);
        expect(
            screen.getByText('Charges are for Juan Dela Cruz.'),
        ).toBeInTheDocument();

        // Switching payer must not carry the previous family's child across —
        // the server rejects that pairing, and it bills a stranger's parent.
        chooseCustomer(/Ana Reyes/);

        expect(
            screen.queryByText('Charges are for Juan Dela Cruz.'),
        ).not.toBeInTheDocument();
    });

    it('filters the customer list by name', () => {
        renderPickers();

        fireEvent.click(screen.getByLabelText('Customer'));
        fireEvent.change(
            screen.getByPlaceholderText(/search by name or tin/i),
            {
                target: { value: 'reyes' },
            },
        );

        expect(
            screen.getByRole('option', { name: /Ana Reyes/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: /Barangay Malanday/ }),
        ).not.toBeInTheDocument();
    });

    it('filters the customer list by TIN, which is what a BIR document shows', () => {
        renderPickers();

        fireEvent.click(screen.getByLabelText('Customer'));
        fireEvent.change(
            screen.getByPlaceholderText(/search by name or tin/i),
            {
                target: { value: '123-456-789' },
            },
        );

        expect(
            screen.getByRole('option', { name: /Ana Reyes/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: /Dela Cruz Family/ }),
        ).not.toBeInTheDocument();
    });

    it('keeps the student picker away from a supplier bill', () => {
        render(
            <InvoiceForm
                mode={{ kind: 'create', type: 'purchase' }}
                contactOptions={PAYER_CONTACTS}
                accountOptions={ACCOUNTS}
                taxRateOptions={RATES}
                studentOptions={STUDENTS}
            />,
        );

        fireEvent.click(screen.getByLabelText('Supplier'));
        fireEvent.click(screen.getByRole('option', { name: /Ana Reyes/ }));

        expect(screen.queryByLabelText('Student')).not.toBeInTheDocument();
    });
});

/*
 * Raising a customer without abandoning the draft. The sheet is the contacts
 * register's own, and the contact comes back through refreshed props rather
 * than through a callback — so what is exercised here is the picker's offer
 * and the form's reaction to a list that has grown.
 */
describe('adding a customer from the picker', () => {
    const CONTACT = { id: 1, name: 'Dela Cruz Family', tin: null };
    const ADDED = { id: 2, name: 'Reyes Family', tin: null };

    function renderPicker(
        props: Partial<React.ComponentProps<typeof InvoiceForm>> = {},
    ) {
        return render(
            <InvoiceForm
                mode={{ kind: 'create', type: 'sales' }}
                contactOptions={[CONTACT]}
                accountOptions={ACCOUNTS}
                taxRateOptions={RATES}
                {...props}
            />,
        );
    }

    it('offers New customer inside the picker', () => {
        renderPicker({ canCreateContact: true });

        fireEvent.click(screen.getByLabelText('Customer'));

        expect(
            screen.getByRole('button', { name: /new customer/i }),
        ).toBeInTheDocument();
    });

    it('names the supplier on a bill', () => {
        render(
            <InvoiceForm
                mode={{ kind: 'create', type: 'purchase' }}
                contactOptions={[CONTACT]}
                accountOptions={ACCOUNTS}
                taxRateOptions={RATES}
                canCreateContact
            />,
        );

        fireEvent.click(screen.getByLabelText('Supplier'));

        expect(
            screen.getByRole('button', { name: /new supplier/i }),
        ).toBeInTheDocument();
    });

    it('withholds the offer from someone who may not create contacts', () => {
        renderPicker();

        fireEvent.click(screen.getByLabelText('Customer'));

        expect(
            screen.queryByRole('button', { name: /new customer/i }),
        ).not.toBeInTheDocument();
    });

    /*
     * An empty register used to be a dead end — the picker was disabled, and
     * the only way forward was to leave the page for the contacts list, which
     * is precisely the draft-losing trip this button exists to avoid.
     */
    it('opens on an empty register when the operator can create one', () => {
        renderPicker({ contactOptions: [], canCreateContact: true });

        const trigger = screen.getByLabelText('Customer');
        expect(trigger).not.toBeDisabled();
        expect(trigger).toHaveTextContent('No customers yet');

        fireEvent.click(trigger);

        expect(
            screen.getByRole('button', { name: /new customer/i }),
        ).toBeInTheDocument();
    });

    it('selects a contact that was not in the list a moment ago', () => {
        const { rerender } = renderPicker({ canCreateContact: true });

        expect(screen.getByLabelText('Customer')).toHaveTextContent(
            'Choose a customer',
        );

        // What the redirect back from contacts.store looks like from here:
        // the same component, one more option, draft state untouched.
        rerender(
            <InvoiceForm
                mode={{ kind: 'create', type: 'sales' }}
                contactOptions={[CONTACT, ADDED]}
                accountOptions={ACCOUNTS}
                taxRateOptions={RATES}
                canCreateContact
            />,
        );

        expect(screen.getByLabelText('Customer')).toHaveTextContent(
            'Reyes Family',
        );
    });

    it('leaves an existing choice alone when nothing was added', () => {
        const { rerender } = renderPicker({ canCreateContact: true });

        fireEvent.click(screen.getByLabelText('Customer'));
        fireEvent.click(screen.getByRole('option', { name: /Dela Cruz/ }));

        rerender(
            <InvoiceForm
                mode={{ kind: 'create', type: 'sales' }}
                contactOptions={[CONTACT]}
                accountOptions={ACCOUNTS}
                taxRateOptions={RATES}
                canCreateContact
            />,
        );

        expect(screen.getByLabelText('Customer')).toHaveTextContent(
            'Dela Cruz Family',
        );
    });
});

describe('date fields', () => {
    /*
     * `<Input type="date">` renders differently in every browser, ignores the
     * theme, and cannot be cleared once set — which is exactly what a due date
     * on "due on receipt" terms needs (CODING_STANDARDS_REACT.md §Date
     * inputs). The trigger is a button, so a native date input in the document
     * is the regression.
     */
    it('uses the date picker rather than a native date input', () => {
        renderForm();

        expect(document.querySelector('input[type="date"]')).toBeNull();
        expect(screen.getByLabelText('Issue date').tagName).toBe('BUTTON');
        expect(screen.getByLabelText('Due date')).toHaveTextContent(
            'No due date',
        );
    });

    it('shows today on the issue date, formatted for reading', () => {
        renderForm();

        // buildDefaults seeds today; the picker shows 'MMM d, yyyy'.
        const label = new Date().toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });

        expect(screen.getByLabelText('Issue date')).toHaveTextContent(label);
    });
});

/*
 * Setting the invoice to come round again.
 *
 * The cadence is the only thing this form sends. The day of the month and the
 * payment terms are read off the invoice's own dates on the server, so the
 * schedule cannot disagree with the document it came from — and the summary
 * line is how an operator sees that derivation before committing to it.
 *
 * The issue date is a DatePicker, not a text box, so these drive it the way a
 * form actually gets one: the default is today, and today is pinned with fake
 * timers. That also lets the short-month case be tested at all.
 */
describe('repeating an invoice', () => {
    function renderOn(date: string): void {
        const [year, month, day] = date.split('-').map(Number);

        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date(year, month - 1, day, 12));

        render(
            <InvoiceForm
                mode={{ kind: 'create', type: 'sales' }}
                contactOptions={CONTACTS}
                accountOptions={ACCOUNTS}
                taxRateOptions={RATES}
            />,
        );
    }

    afterEach(() => {
        vi.useRealTimers();
    });

    function tickRepeat(): void {
        fireEvent.click(screen.getByLabelText('Repeat this invoice'));
    }

    it('offers to repeat a sales invoice', () => {
        renderForm();

        expect(
            screen.getByLabelText('Repeat this invoice'),
        ).toBeInTheDocument();
    });

    it('keeps the cadence out of sight until it is asked for', () => {
        // Most invoices are raised once. A form that quietly commits a school
        // to billing a family every month would be a bad default to get wrong.
        renderForm();

        expect(screen.queryByLabelText('Every')).not.toBeInTheDocument();

        tickRepeat();

        expect(screen.getByLabelText('Every')).toBeInTheDocument();
    });

    it('does not offer it on a supplier bill', () => {
        // A bill is the supplier's own document; repeating it would be this
        // school promising to receive something.
        render(
            <InvoiceForm
                mode={{ kind: 'create', type: 'purchase' }}
                contactOptions={CONTACTS}
                accountOptions={ACCOUNTS}
                taxRateOptions={RATES}
            />,
        );

        expect(
            screen.queryByLabelText('Repeat this invoice'),
        ).not.toBeInTheDocument();
    });

    it('reads the cadence day off the issue date', () => {
        renderOn('2026-08-15');
        tickRepeat();

        expect(screen.getByText(/on day 15/)).toBeInTheDocument();
    });

    it('names the next invoice date, so a wrong issue date is caught early', () => {
        renderOn('2026-08-15');
        tickRepeat();

        expect(
            screen.getByText(/Next invoice 15 September 2026/),
        ).toBeInTheDocument();
    });

    it('does not let the day stick when a short month intervenes', () => {
        // Mirrors RecurringInvoice::onDayOf(). JavaScript's own setMonth rolls
        // 31 January over to 3 March, which would show the operator a date the
        // server is never going to bill.
        renderOn('2026-01-31');
        tickRepeat();

        expect(
            screen.getByText(/Next invoice 28 February 2026/),
        ).toBeInTheDocument();
    });

    it('repeats due-on-receipt terms as due on receipt', () => {
        // No due date on the invoice, so none on the schedule either.
        renderOn('2026-08-01');
        tickRepeat();

        expect(screen.getByText(/due on receipt/)).toBeInTheDocument();
    });

    it('follows the chosen cadence, not just months', () => {
        renderOn('2026-08-15');
        tickRepeat();

        fireEvent.click(screen.getByLabelText('Every'));
        fireEvent.click(screen.getByRole('option', { name: 'Quarterly' }));

        expect(
            screen.getByText(/Next invoice 15 November 2026/),
        ).toBeInTheDocument();
    });

    it('suggests a schedule name from the payer rather than demanding one', () => {
        // The operator is raising a document, not naming a rule. The server
        // derives the same name when the box is left empty.
        renderForm();

        fireEvent.click(screen.getByLabelText('Customer'));
        fireEvent.click(screen.getByRole('option', { name: /Dela Cruz/ }));
        tickRepeat();

        expect(screen.getByLabelText('Name this schedule')).toHaveAttribute(
            'placeholder',
            expect.stringContaining('Dela Cruz Family'),
        );
    });
});
