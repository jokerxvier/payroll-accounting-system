import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ChartOfAccountEditSheet } from '@/components/admin/chart-of-account-edit-sheet';
import type { AccountOption, ChartOfAccountRow } from '@/types';

const post = vi.fn();
const patch = vi.fn();

// Minimal useForm shim covering the surface the sheet uses. Submissions are
// no-ops; the assertions here are about field state and which verb fires.
vi.mock('@inertiajs/react', async () => {
    const { useState: useStateInner } = await import('react');

    return {
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

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

function account(
    overrides: Partial<ChartOfAccountRow> = {},
): ChartOfAccountRow {
    return {
        id: 7,
        code: '5100',
        name: 'Salaries and Wages',
        type: 'expense',
        subtype: 'operating_expense',
        normal_balance: 'debit',
        cash_flow_category: 'operating',
        parent_id: null,
        system_code: null,
        description: null,
        is_active: true,
        is_locked: false,
        ...overrides,
    };
}

const PARENTS: AccountOption[] = [
    { id: 7, code: '5100', name: 'Salaries and Wages', type: 'expense' },
    { id: 8, code: '1100', name: 'Cash on Hand', type: 'asset' },
];

function Harness(props: {
    account?: ChartOfAccountRow;
    parentOptions?: AccountOption[];
}) {
    const [open, setOpen] = useState(true);

    return (
        <ChartOfAccountEditSheet
            open={open}
            onOpenChange={setOpen}
            account={props.account}
            parentOptions={props.parentOptions ?? PARENTS}
        />
    );
}

describe('ChartOfAccountEditSheet', () => {
    it('titles itself for a new account when given no row', () => {
        render(<Harness />);

        expect(screen.getByText('New account')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Create account' }),
        ).toBeInTheDocument();
    });

    it('titles itself for the row being edited', () => {
        render(<Harness account={account()} />);

        expect(
            screen.getByText('Edit 5100 — Salaries and Wages'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Save changes' }),
        ).toBeInTheDocument();
    });

    it('prefills the fields from the row', () => {
        render(<Harness account={account()} />);

        expect(screen.getByLabelText('Code')).toHaveValue('5100');
        expect(screen.getByLabelText('Name')).toHaveValue('Salaries and Wages');
    });

    it('previews the normal balance implied by the type', () => {
        // An expense is debit-normal. The server derives this from `type` and
        // ignores whatever the client sends, so the sheet only previews it.
        render(<Harness account={account()} />);

        expect(screen.getByText('debit')).toBeInTheDocument();
    });

    it('previews a credit normal balance for a credit-normal type', () => {
        render(
            <Harness
                account={account({ type: 'income', normal_balance: 'credit' })}
            />,
        );

        expect(screen.getByText('credit')).toBeInTheDocument();
    });

    it('freezes code and type on a locked system account', () => {
        render(
            <Harness
                account={account({
                    id: 3,
                    code: '1200',
                    name: 'Accounts Receivable',
                    type: 'asset',
                    normal_balance: 'debit',
                    system_code: 'AR_CONTROL',
                    is_locked: true,
                })}
            />,
        );

        // Renaming stays available; the identity fields do not, because
        // posted journal entries refer to them.
        expect(screen.getByLabelText('Code')).toBeDisabled();
        expect(screen.getByLabelText('Name')).toBeEnabled();
        expect(
            screen.getByText(/system posts to this account automatically/i),
        ).toBeInTheDocument();
    });

    it('patches when editing and posts when creating', () => {
        const { unmount } = render(<Harness account={account()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(patch).toHaveBeenCalledTimes(1);
        expect(post).not.toHaveBeenCalled();
        unmount();

        render(<Harness />);
        fireEvent.click(screen.getByRole('button', { name: 'Create account' }));
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('closes without submitting when cancelled', () => {
        render(<Harness account={account()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(
            screen.queryByText('Edit 5100 — Salaries and Wages'),
        ).not.toBeInTheDocument();
    });
});
