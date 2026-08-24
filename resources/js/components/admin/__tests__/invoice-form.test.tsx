import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { InvoiceForm } from '@/components/admin/invoice-form';
import type {
    InvoiceAccountOption,
    InvoiceContactOption,
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
                key: keyof T | Partial<T>,
                value?: T[keyof T],
            ): void => {
                if (typeof key === 'string') {
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
            nextNumber="SI-000001"
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

    it('names the serial the document would take', () => {
        renderForm();

        expect(
            screen.getByText(/takes number SI-000001 when it is approved/),
        ).toBeInTheDocument();
    });

    it('warns when no numbering series exists', () => {
        // Approving would fail outright, so the form says so while there is
        // still time to set one up.
        render(
            <InvoiceForm
                mode={{ kind: 'create', type: 'sales' }}
                contactOptions={CONTACTS}
                accountOptions={ACCOUNTS}
                taxRateOptions={RATES}
                nextNumber={null}
            />,
        );

        expect(
            screen.getByText(/No numbering series is set up/),
        ).toBeInTheDocument();
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
