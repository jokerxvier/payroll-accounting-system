import {
    fireEvent,
    render as rtlRender,
    screen,
    within,
} from '@testing-library/react';
import type { ReactElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import ChartOfAccountsIndex from '@/pages/admin/accounting/chart-of-accounts/index';
import type { ChartOfAccountRow } from '@/types';

/**
 * The page uses a bare <Tooltip> for the system-account marker, exactly as
 * the sidebar does — both rely on the single TooltipProvider that app.tsx
 * wraps the whole application in. Mirror that here so the unit under test
 * sees the same context it does in the browser.
 */
function render(ui: ReactElement) {
    return rtlRender(<TooltipProvider delayDuration={0}>{ui}</TooltipProvider>);
}

// The page renders synchronously; stub only the Inertia navigation
// primitives so the tree mounts without a real Inertia provider.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { delete: vi.fn() },
    usePage: () => ({ props: { auth: { user: null } } }),
    // The import dialog renders with the page and runs two forms of its own.
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        clearErrors: vi.fn(),
        processing: false,
        errors: {},
    }),
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

// The sheet has its own test file. Here we only care that the index opens
// it with the right row, so it is stubbed down to something assertable.
vi.mock('@/components/admin/chart-of-account-edit-sheet', () => ({
    ChartOfAccountEditSheet: ({
        open,
        account,
    }: {
        open: boolean;
        account?: { code: string };
    }) =>
        open ? (
            <div data-testid="edit-sheet">
                {account ? `editing:${account.code}` : 'creating'}
            </div>
        ) : null,
}));

function account(
    overrides: Partial<ChartOfAccountRow> = {},
): ChartOfAccountRow {
    return {
        id: 1,
        code: '5100',
        name: 'Salaries and Wages',
        type: 'expense',
        subtype: 'operating_expense',
        normal_balance: 'debit',
        cash_flow_category: 'operating',
        is_cash_equivalent: false,
        parent_id: null,
        system_code: null,
        description: null,
        is_active: true,
        is_locked: false,
        ...overrides,
    };
}

describe('ChartOfAccountsIndex', () => {
    // The active tab is deep-linked into the query string, and jsdom shares
    // one location across the file. Reset it so each test starts on the
    // combined view rather than inheriting the previous test's selection.
    beforeEach(() => {
        window.history.replaceState({}, '', '/');
    });

    it('groups accounts into statement sections in conventional order', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    // Deliberately out of order — the page must impose the
                    // conventional balance-sheet-then-income-statement
                    // sequence rather than echo the array order.
                    account({ id: 1, code: '5100', type: 'expense' }),
                    account({
                        id: 2,
                        code: '1100',
                        name: 'Cash on Hand',
                        type: 'asset',
                        normal_balance: 'debit',
                    }),
                    account({
                        id: 3,
                        code: '4100',
                        name: 'Tuition Fee Income',
                        type: 'income',
                        normal_balance: 'credit',
                    }),
                    account({
                        id: 4,
                        code: '2100',
                        name: 'Accounts Payable',
                        type: 'liability',
                        normal_balance: 'credit',
                    }),
                ]}
            />,
        );

        // Scoped to the table: the tab bar carries the same five labels, and
        // this assertion is about the order the sections are read in.
        const headings = within(screen.getByRole('table'))
            .getAllByText(/^(Assets|Liabilities|Equity|Revenue|Expenses)$/)
            .map((node) => node.textContent);

        expect(headings).toEqual([
            'Assets',
            'Liabilities',
            'Revenue',
            'Expenses',
        ]);
    });

    it('omits a section that has no accounts', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[account({ type: 'asset', normal_balance: 'debit' })]}
            />,
        );

        // The section is omitted from the combined view; the tab stays, so
        // the bar lists the same five types for every tenant.
        const table = within(screen.getByRole('table'));

        expect(table.getByText('Assets')).toBeInTheDocument();
        expect(table.queryByText('Equity')).not.toBeInTheDocument();
        expect(table.queryByText('Expenses')).not.toBeInTheDocument();

        expect(screen.getByRole('tab', { name: /Equity/ })).toBeInTheDocument();
    });

    it('shows the normal balance for each account', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    account({
                        id: 1,
                        code: '1100',
                        type: 'asset',
                        normal_balance: 'debit',
                    }),
                    account({
                        id: 2,
                        code: '2100',
                        type: 'liability',
                        normal_balance: 'credit',
                    }),
                ]}
            />,
        );

        expect(screen.getByText('debit')).toBeInTheDocument();
        expect(screen.getByText('credit')).toBeInTheDocument();
    });

    it('marks a system account and hides its delete control', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    account({
                        id: 1,
                        code: '1200',
                        name: 'Accounts Receivable',
                        type: 'asset',
                        normal_balance: 'debit',
                        system_code: 'AR_CONTROL',
                        is_locked: true,
                    }),
                ]}
            />,
        );

        expect(screen.getByText('System')).toBeInTheDocument();
        // Locked accounts stay editable but must not offer deletion — the
        // policy refuses it, so the control would be a dead end.
        expect(screen.getByLabelText('Edit account 1200')).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Delete account 1200'),
        ).not.toBeInTheDocument();
    });

    it('offers deletion for an ordinary account', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[account({ id: 1, code: '5900' })]}
            />,
        );

        expect(
            screen.getByLabelText('Delete account 5900'),
        ).toBeInTheDocument();
    });

    it('renders an empty state when the chart has no accounts', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[]}
            />,
        );

        expect(screen.getByText('No accounts yet')).toBeInTheDocument();
    });

    it('hides the create action when the viewer cannot create', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: false }}
                accounts={[]}
            />,
        );

        expect(screen.queryByText('New account')).not.toBeInTheDocument();
    });

    it('offers a tab per account type plus a combined view', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    account({ id: 1, code: '1100', type: 'asset' }),
                    account({ id: 2, code: '5100', type: 'expense' }),
                    account({ id: 3, code: '5200', type: 'expense' }),
                ]}
            />,
        );

        expect(
            screen.getAllByRole('tab').map((tab) => tab.textContent),
        ).toEqual([
            'All accounts3',
            'Assets1',
            'Liabilities0',
            'Equity0',
            'Revenue0',
            'Expenses2',
        ]);
    });

    it('narrows the table to one type when its tab is selected', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    account({ id: 1, code: '1100', type: 'asset' }),
                    account({ id: 2, code: '5100', type: 'expense' }),
                ]}
            />,
        );

        // The combined tab shows both.
        expect(screen.getByText('1100')).toBeInTheDocument();
        expect(screen.getByText('5100')).toBeInTheDocument();

        // Radix activates a tab on mousedown (and on focus in automatic mode);
        // a bare click in jsdom moves neither, so the tab would never change.
        fireEvent.mouseDown(screen.getByRole('tab', { name: /Expenses/ }));

        const table = within(screen.getByRole('table'));

        expect(table.getByText('5100')).toBeInTheDocument();
        expect(table.queryByText('1100')).not.toBeInTheDocument();

        // The tab names the type, so the section heading would only repeat it.
        expect(table.queryByText('Expenses')).not.toBeInTheDocument();
    });

    it('files accounts under a heading per subtype', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    account({
                        id: 1,
                        code: '1100',
                        name: 'Cash on Hand',
                        type: 'asset',
                        subtype: 'current_asset',
                    }),
                    account({
                        id: 2,
                        code: '1200',
                        name: 'Tuition Receivable',
                        type: 'asset',
                        subtype: 'current_asset',
                    }),
                    account({
                        id: 3,
                        code: '1500',
                        name: 'School Equipment',
                        type: 'asset',
                        subtype: 'non_current_asset',
                    }),
                ]}
            />,
        );

        const table = within(screen.getByRole('table'));

        expect(table.getByText('Current assets')).toBeInTheDocument();
        expect(table.getByText('Non-current assets')).toBeInTheDocument();
        expect(table.getByText('Cash on Hand')).toBeInTheDocument();
        expect(table.getByText('School Equipment')).toBeInTheDocument();
    });

    it('collapses a subtype group, hiding only its own accounts', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    account({
                        id: 1,
                        code: '1100',
                        name: 'Cash on Hand',
                        type: 'asset',
                        subtype: 'current_asset',
                    }),
                    account({
                        id: 3,
                        code: '1500',
                        name: 'School Equipment',
                        type: 'asset',
                        subtype: 'non_current_asset',
                    }),
                ]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Current assets/ }));

        const table = within(screen.getByRole('table'));

        expect(table.queryByText('Cash on Hand')).not.toBeInTheDocument();
        expect(table.getByText('School Equipment')).toBeInTheDocument();
        // The heading stays so the group can be reopened.
        expect(table.getByText('Current assets')).toBeInTheDocument();
    });

    it('nests a sub-account under its parent and collapses with it', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    account({
                        id: 1,
                        code: '1100',
                        name: 'Cash',
                        type: 'asset',
                        subtype: 'current_asset',
                    }),
                    account({
                        id: 2,
                        code: '1110',
                        name: 'Bank Accounts',
                        type: 'asset',
                        subtype: 'current_asset',
                        parent_id: 1,
                    }),
                ]}
            />,
        );

        const table = () => within(screen.getByRole('table'));

        expect(table().getByText('Bank Accounts')).toBeInTheDocument();

        fireEvent.click(screen.getByLabelText('Collapse sub-accounts of 1100'));

        expect(table().queryByText('Bank Accounts')).not.toBeInTheDocument();
        expect(table().getByText('Cash')).toBeInTheDocument();
    });

    it('keeps an account whose parent is outside the tab', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    // Parent 99 is not in the chart at all. The row must still
                    // appear, at the top level, rather than vanish.
                    account({
                        id: 2,
                        code: '1110',
                        name: 'Bank Accounts',
                        type: 'asset',
                        subtype: 'current_asset',
                        parent_id: 99,
                    }),
                ]}
            />,
        );

        expect(
            within(screen.getByRole('table')).getByText('Bank Accounts'),
        ).toBeInTheDocument();
    });

    it('counts the accounts in each section', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    account({ id: 1, code: '5100' }),
                    account({ id: 2, code: '5200' }),
                    account({ id: 3, code: '5300' }),
                ]}
            />,
        );

        const heading = within(screen.getByRole('table')).getByText('Expenses');
        const row = heading.closest('tr');
        expect(row).not.toBeNull();
        expect(within(row as HTMLElement).getByText('3')).toBeInTheDocument();
    });
});

describe('ChartOfAccountsIndex — edit sheet', () => {
    it('keeps the sheet closed until something asks for it', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[account({ id: 1, code: '5100' })]}
            />,
        );

        expect(screen.queryByTestId('edit-sheet')).not.toBeInTheDocument();
    });

    it('opens the sheet in create mode from the header action', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[account({ id: 1, code: '5100' })]}
            />,
        );

        fireEvent.click(screen.getByText('New account'));

        expect(screen.getByTestId('edit-sheet')).toHaveTextContent('creating');
    });

    it('opens the sheet in create mode from the empty state', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[]}
            />,
        );

        // With no accounts, both the page header and the empty state offer
        // "New account". Click the second — the empty-state one is the
        // subject here, and the header is already covered above.
        const triggers = screen.getAllByText('New account');
        expect(triggers).toHaveLength(2);
        fireEvent.click(triggers[1]);

        expect(screen.getByTestId('edit-sheet')).toHaveTextContent('creating');
    });

    it('opens the sheet on the row that was clicked', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    account({ id: 1, code: '5100' }),
                    account({ id: 2, code: '5200' }),
                ]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Edit account 5200'));

        expect(screen.getByTestId('edit-sheet')).toHaveTextContent(
            'editing:5200',
        );
    });

    it('edits a locked system account rather than navigating away', () => {
        render(
            <ChartOfAccountsIndex
                parentOptions={[]}
                can={{ create: true }}
                accounts={[
                    account({
                        id: 1,
                        code: '1200',
                        type: 'asset',
                        normal_balance: 'debit',
                        system_code: 'AR_CONTROL',
                        is_locked: true,
                    }),
                ]}
            />,
        );

        fireEvent.click(screen.getByLabelText('Edit account 1200'));

        expect(screen.getByTestId('edit-sheet')).toHaveTextContent(
            'editing:1200',
        );
    });
});
