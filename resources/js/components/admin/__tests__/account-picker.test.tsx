import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AccountPicker } from '@/components/admin/account-picker';
import type { LedgerAccountOption } from '@/types/ledger-report';

/*
 * The account combobox.
 *
 * It exists because a seeded chart is forty accounts before a school adds any
 * of its own, and the two things worth pinning are the two ways an accountant
 * actually reaches for one: by the code they are holding, and by a name they
 * half remember. Type grouping is the third — it is what makes "an expense
 * account, one of the utility ones" a findable thing.
 */

const ACCOUNTS: LedgerAccountOption[] = [
    {
        id: 1,
        code: '1100',
        name: 'Cash on Hand',
        type: 'asset',
        is_active: true,
    },
    {
        id: 2,
        code: '1200',
        name: 'Accounts Receivable',
        type: 'asset',
        is_active: true,
    },
    {
        id: 3,
        code: '2100',
        name: 'Accounts Payable',
        type: 'liability',
        is_active: true,
    },
    {
        id: 4,
        code: '4100',
        name: 'Tuition Fee Income',
        type: 'income',
        is_active: true,
    },
    {
        id: 5,
        code: '5210',
        name: 'Utilities Expense',
        type: 'expense',
        is_active: true,
    },
    {
        id: 6,
        code: '5900',
        name: 'Retired Account',
        type: 'expense',
        is_active: false,
    },
];

function open(
    options: LedgerAccountOption[] = ACCOUNTS,
    value: number | null = null,
) {
    const onSelect = vi.fn();

    render(
        <AccountPicker
            id="account_id"
            options={options}
            value={value}
            onSelect={onSelect}
        />,
    );

    fireEvent.click(screen.getByRole('combobox'));

    return onSelect;
}

function search(term: string): void {
    fireEvent.change(screen.getByPlaceholderText(/search by code or name/i), {
        target: { value: term },
    });
}

describe('searching', () => {
    it('finds an account by its code', () => {
        // The case the plain Select could not serve: an accountant holding a
        // document has the code, not the spelling.
        open();
        search('5210');

        expect(screen.getByText('Utilities Expense')).toBeInTheDocument();
        expect(screen.queryByText('Cash on Hand')).not.toBeInTheDocument();
    });

    it('finds an account by part of its name', () => {
        open();
        search('receiv');

        expect(screen.getByText('Accounts Receivable')).toBeInTheDocument();
        expect(screen.queryByText('Accounts Payable')).not.toBeInTheDocument();
    });

    it('finds accounts by their type', () => {
        // "an expense account" is how somebody starts looking when they know
        // the kind but not the row.
        open();
        search('Expenses');

        expect(screen.getByText('Utilities Expense')).toBeInTheDocument();
        expect(
            screen.queryByText('Tuition Fee Income'),
        ).not.toBeInTheDocument();
    });

    it('says so when nothing matches', () => {
        open();
        search('nothing like this');

        expect(screen.getByText('No account found.')).toBeInTheDocument();
    });
});

describe('grouping', () => {
    it('files each account under its type, Revenue not income', () => {
        // The chart stores `income`; the interface calls it Revenue
        // everywhere, and this must not be the one place it does not.
        open();

        for (const heading of [
            'Assets',
            'Liabilities',
            'Revenue',
            'Expenses',
        ]) {
            expect(screen.getByText(heading)).toBeInTheDocument();
        }

        expect(screen.queryByText('income')).not.toBeInTheDocument();
    });

    it('keeps an account with an unrecognised type reachable', () => {
        // Hand-made rows with odd types do turn up in a live chart. Dropping
        // one would make it invisible in the one place built for finding
        // accounts.
        open([
            ...ACCOUNTS,
            {
                id: 99,
                code: '9999',
                name: 'Oddity',
                type: 'mystery',
                is_active: true,
            },
        ]);

        expect(screen.getByText('Other')).toBeInTheDocument();
        expect(screen.getByText('Oddity')).toBeInTheDocument();
    });
});

describe('choosing', () => {
    it('reports the account that was picked', () => {
        const onSelect = open();

        fireEvent.click(screen.getByText('Tuition Fee Income'));

        expect(onSelect).toHaveBeenCalledWith(4);
    });

    it('shows the chosen account on the trigger, code and all', () => {
        render(
            <AccountPicker
                id="account_id"
                options={ACCOUNTS}
                value={5}
                onSelect={vi.fn()}
            />,
        );

        const trigger = screen.getByRole('combobox');

        expect(within(trigger).getByText('5210')).toBeInTheDocument();
        expect(trigger).toHaveTextContent('Utilities Expense');
    });

    it('marks an inactive account rather than hiding it', () => {
        // It may still hold a balance somebody has to look at, and a report
        // that cannot open it is a dead end.
        open();

        const row = screen.getByText('Retired Account').closest('[role]');

        expect(row).not.toBeNull();
        expect(row).toHaveTextContent('inactive');
    });
});

describe('an empty chart', () => {
    it('disables the control and says why', () => {
        render(
            <AccountPicker
                id="account_id"
                options={[]}
                value={null}
                onSelect={vi.fn()}
            />,
        );

        expect(screen.getByRole('combobox')).toBeDisabled();
        expect(screen.getByText('No accounts yet')).toBeInTheDocument();
    });
});
