import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import GeneralLedgerReport from '@/pages/admin/accounting/reports/general-ledger';
import type { AccountLedger, AccountLedgerLine } from '@/types/ledger-report';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        get: vi.fn(),
        processing: false,
    }),
}));

function line(overrides: Partial<AccountLedgerLine> = {}): AccountLedgerLine {
    return {
        line_id: 1,
        entry_id: 10,
        entry_number: 'JE-2026-00001',
        date: '2026-08-15',
        reference: 'INV-0001',
        narration: 'Tuition collected',
        description: null,
        debit_centavos: 500_000,
        credit_centavos: 0,
        running_raw_centavos: 500_000,
        contra_accounts: ['4100 Tuition Fee Income'],
        is_reversal: false,
        ...overrides,
    };
}

function ledger(overrides: Partial<AccountLedger> = {}): AccountLedger {
    return {
        account: {
            id: 1,
            code: '1100',
            name: 'Cash on Hand',
            type: 'asset',
            normal_balance: 'debit',
        },
        opening_raw_centavos: 0,
        closing_raw_centavos: 500_000,
        closing_natural_centavos: 500_000,
        total_debit_centavos: 500_000,
        total_credit_centavos: 0,
        lines: [line()],
        ...overrides,
    };
}

function renderPage(value: AccountLedger | null) {
    return render(
        <GeneralLedgerReport
            filters={{
                from: '2026-08-01',
                to: '2026-08-31',
                account_id: value ? value.account.id : null,
            }}
            accountOptions={[
                {
                    id: 1,
                    code: '1100',
                    name: 'Cash on Hand',
                    type: 'asset',
                    is_active: true,
                },
            ]}
            ledger={value}
        />,
    );
}

describe('general ledger report', () => {
    it('asks for an account instead of showing an empty table', () => {
        renderPage(null);

        expect(
            screen.getByText('Choose an account to read its ledger.'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('opens with the balance brought forward', () => {
        // Without it the running balance column starts from a number the
        // reader cannot check without fetching the prior period.
        renderPage(ledger({ opening_raw_centavos: 250_000 }));

        const table = screen.getByRole('table');
        expect(
            within(table).getByText('Balance brought forward'),
        ).toBeInTheDocument();
        expect(within(table).getByText('2,500.00')).toBeInTheDocument();
    });

    it('names the other side of each line', () => {
        renderPage(ledger());

        expect(screen.getByText('4100 Tuition Fee Income')).toBeInTheDocument();
    });

    it('marks a reversing line', () => {
        renderPage(
            ledger({
                lines: [line({ is_reversal: true })],
            }),
        );

        expect(screen.getByText('reversal')).toBeInTheDocument();
    });

    it('reads a credit balance on a debit-normal account as contra', () => {
        // An overdrawn asset. Printing "5,000.00 debit" here would state the
        // opposite of what happened.
        renderPage(
            ledger({
                closing_raw_centavos: -500_000,
                closing_natural_centavos: -500_000,
                total_debit_centavos: 0,
                total_credit_centavos: 500_000,
            }),
        );

        expect(screen.getByText(/contra/)).toBeInTheDocument();
    });

    it('says so when the account had no movement in the range', () => {
        renderPage(
            ledger({
                lines: [],
                closing_raw_centavos: 0,
                total_debit_centavos: 0,
                total_credit_centavos: 0,
                closing_natural_centavos: 0,
            }),
        );

        expect(
            screen.getByText(/No posted movement on this account/),
        ).toBeInTheDocument();
    });
});
