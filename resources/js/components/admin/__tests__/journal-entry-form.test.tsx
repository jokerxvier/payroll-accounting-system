import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { JournalEntryForm } from '@/components/admin/journal-entry-form';
import type { JournalAccountOption } from '@/types';

const post = vi.fn();
const patch = vi.fn();

// Minimal useForm shim covering the surface the form uses. The assertions
// here are about the running balance and line mechanics, not submission.
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
                patch,
                reset: vi.fn(),
                clearErrors: vi.fn(),
                setDefaults: vi.fn(),
            };
        },
    };
});

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const ACCOUNTS: JournalAccountOption[] = [
    {
        id: 1,
        code: '1100',
        name: 'Cash',
        type: 'asset',
        normal_balance: 'debit',
    },
    {
        id: 2,
        code: '4100',
        name: 'Tuition',
        type: 'income',
        normal_balance: 'credit',
    },
];

function Harness() {
    const [, force] = useState(0);
    void force;

    return (
        <JournalEntryForm mode={{ kind: 'create' }} accountOptions={ACCOUNTS} />
    );
}

function typeAmount(label: string, value: string): void {
    fireEvent.change(screen.getByLabelText(label), { target: { value } });
}

/**
 * Type character by character, the way a person does.
 *
 * `fireEvent.change` with a whole string at once round-trips fine even
 * through a badly controlled input, which is exactly how the reformat-on-
 * every-keystroke bug shipped past the first version of these tests. Each
 * character is appended to whatever the input is currently displaying, so a
 * value that snaps back mid-entry shows up here.
 */
function typeChars(label: string, chars: string): void {
    const input = screen.getByLabelText(label) as HTMLInputElement;

    for (const char of chars) {
        fireEvent.change(input, { target: { value: input.value + char } });
    }
}

describe('JournalEntryForm', () => {
    it('starts with the two lines double-entry requires', () => {
        render(<Harness />);

        expect(screen.getByLabelText('Account for line 1')).toBeInTheDocument();
        expect(screen.getByLabelText('Account for line 2')).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Account for line 3'),
        ).not.toBeInTheDocument();
    });

    it('reports the entry as out of balance while the sides differ', () => {
        render(<Harness />);

        typeAmount('Debit for line 1', '5000');

        // 5,000 debited against nothing credited — the operator needs to see
        // the gap, not discover it on submit.
        expect(screen.getByText('Out by')).toBeInTheDocument();
        expect(screen.queryByText('Balanced')).not.toBeInTheDocument();
    });

    it('reports balanced once the sides agree', () => {
        render(<Harness />);

        typeAmount('Debit for line 1', '5000');
        typeAmount('Credit for line 2', '5000');

        expect(screen.getByText('Balanced')).toBeInTheDocument();
        expect(screen.queryByText('Out by')).not.toBeInTheDocument();
    });

    it('does not call an empty entry balanced', () => {
        // Zero equals zero, but an entry that moves nothing is not a valid
        // entry — the server refuses it too.
        render(<Harness />);

        expect(screen.queryByText('Balanced')).not.toBeInTheDocument();
    });

    it('shows how far out the entry is', () => {
        render(<Harness />);

        typeAmount('Debit for line 1', '5000');
        typeAmount('Credit for line 2', '4000');

        const summary = screen.getByText('Out by').closest('div');
        expect(summary).not.toBeNull();
        // The difference is what points at the mistyped figure.
        expect(
            within(summary as HTMLElement).getByText(/1,000\.00/),
        ).toBeInTheDocument();
    });

    it('clears the other side when one side is entered', () => {
        render(<Harness />);

        // The input shows what was typed, not a reformatted version of it —
        // reformatting mid-entry is what made the field impossible to type
        // in. Normalising happens on blur.
        typeAmount('Debit for line 1', '5000');
        expect(screen.getByLabelText('Debit for line 1')).toHaveValue('5000');

        // A line moves exactly one side; the server rejects both being set,
        // so the form must not let it happen in the first place.
        typeAmount('Credit for line 1', '3000');
        expect(screen.getByLabelText('Credit for line 1')).toHaveValue('3000');
        expect(screen.getByLabelText('Debit for line 1')).toHaveValue('');
    });

    it('adds a line', () => {
        render(<Harness />);

        fireEvent.click(screen.getByText('Add line'));

        expect(screen.getByLabelText('Account for line 3')).toBeInTheDocument();
    });

    it('removes an added line but never drops below two', () => {
        render(<Harness />);

        fireEvent.click(screen.getByText('Add line'));
        fireEvent.click(screen.getByLabelText('Remove line 3'));
        expect(
            screen.queryByLabelText('Account for line 3'),
        ).not.toBeInTheDocument();

        // The remaining two cannot be removed — double-entry needs both.
        expect(screen.getByLabelText('Remove line 1')).toBeDisabled();
        expect(screen.getByLabelText('Remove line 2')).toBeDisabled();
    });

    it('balances across more than two lines', () => {
        render(<Harness />);

        fireEvent.click(screen.getByText('Add line'));

        typeAmount('Debit for line 1', '3000');
        typeAmount('Debit for line 2', '2000');
        typeAmount('Credit for line 3', '5000');

        expect(screen.getByText('Balanced')).toBeInTheDocument();
    });

    it('treats a negative figure as zero rather than flipping the side', () => {
        render(<Harness />);

        typeAmount('Debit for line 1', '-5000');

        expect(screen.getByLabelText('Debit for line 1')).toHaveValue('');
    });
});

describe('JournalEntryForm — typing amounts', () => {
    it('lets a multi-digit amount be typed one character at a time', () => {
        render(<Harness />);

        typeChars('Debit for line 1', '5000');

        expect(screen.getByLabelText('Debit for line 1')).toHaveValue('5000');
    });

    it('lets a decimal be typed without the point disappearing', () => {
        render(<Harness />);

        // "1234." is a legitimate half-finished value. Parsing it to a number
        // mid-entry and re-rendering wipes the point the user just pressed.
        typeChars('Credit for line 2', '1234.5');

        expect(screen.getByLabelText('Credit for line 2')).toHaveValue(
            '1234.5',
        );
    });

    it('balances on the amounts actually typed', () => {
        render(<Harness />);

        typeChars('Debit for line 1', '5000');
        typeChars('Credit for line 2', '5000');

        expect(screen.getByText('Balanced')).toBeInTheDocument();
    });

    it('normalises to two decimals on blur', () => {
        render(<Harness />);

        typeChars('Debit for line 1', '5000');
        fireEvent.blur(screen.getByLabelText('Debit for line 1'));

        expect(screen.getByLabelText('Debit for line 1')).toHaveValue(
            '5000.00',
        );
    });

    it('lets an amount be cleared back to empty', () => {
        render(<Harness />);

        typeChars('Debit for line 1', '50');
        fireEvent.change(screen.getByLabelText('Debit for line 1'), {
            target: { value: '' },
        });

        expect(screen.getByLabelText('Debit for line 1')).toHaveValue('');
        expect(screen.queryByText('Balanced')).not.toBeInTheDocument();
    });
});
