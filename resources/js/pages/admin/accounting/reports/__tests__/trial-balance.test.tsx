import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import TrialBalanceReport from '@/pages/admin/accounting/reports/trial-balance';
import type {
    TrialBalanceRow,
    TrialBalanceTotals,
} from '@/types/ledger-report';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        get: vi.fn(),
        processing: false,
    }),
}));

function row(overrides: Partial<TrialBalanceRow> = {}): TrialBalanceRow {
    return {
        account_id: 1,
        code: '1100',
        name: 'Cash on Hand',
        type: 'asset',
        normal_balance: 'debit',
        opening_debit_centavos: 0,
        opening_credit_centavos: 0,
        period_debit_centavos: 500_000,
        period_credit_centavos: 0,
        closing_debit_centavos: 500_000,
        closing_credit_centavos: 0,
        closing_natural_centavos: 500_000,
        ...overrides,
    };
}

function totals(
    overrides: Partial<TrialBalanceTotals> = {},
): TrialBalanceTotals {
    return {
        opening_debit_centavos: 0,
        opening_credit_centavos: 0,
        period_debit_centavos: 500_000,
        period_credit_centavos: 500_000,
        closing_debit_centavos: 500_000,
        closing_credit_centavos: 500_000,
        is_balanced: true,
        closing_variance_centavos: 0,
        ...overrides,
    };
}

function renderPage(
    rows: TrialBalanceRow[],
    totalsOverrides: Partial<TrialBalanceTotals> = {},
) {
    return render(
        <TrialBalanceReport
            filters={{
                from: '2026-08-01',
                to: '2026-08-31',
                include_empty: false,
            }}
            rows={rows}
            totals={totals(totalsOverrides)}
        />,
    );
}

describe('trial balance report', () => {
    it('states the verdict before the reader has to add anything up', () => {
        renderPage([row()]);

        expect(screen.getByText('The ledger balances.')).toBeInTheDocument();
    });

    it('names the direction and size of a discrepancy', () => {
        renderPage([row()], {
            is_balanced: false,
            closing_variance_centavos: 10_000,
            closing_credit_centavos: 490_000,
        });

        expect(
            screen.getByText('The ledger does not balance.'),
        ).toBeInTheDocument();
        // The operator's next move is to search the ledger for the amount, so
        // the amount has to be on the page.
        expect(screen.getByText(/debits exceed credits/)).toBeInTheDocument();
        expect(screen.getByText(/₱100\.00/)).toBeInTheDocument();
    });

    it('says which way round the discrepancy runs', () => {
        renderPage([row()], {
            is_balanced: false,
            closing_variance_centavos: -25_000,
        });

        expect(screen.getByText(/credits exceed debits/)).toBeInTheDocument();
    });

    it('prints a zero column as a dash, not as 0.00', () => {
        // A trial balance showing 0.00 on both sides of one account reads as
        // two offsetting facts instead of one absent one.
        renderPage([row()]);

        // Scoped to the body, and matched exactly. Two ways to get this wrong:
        // `includes('0.00')` also matches the legitimate 5,000.00, and the
        // totals row genuinely prints 0.00 — a total of nothing is still a
        // total, and blanking it would be the actual mistake.
        const body = screen.getByRole('table').querySelector('tbody');
        const cells = Array.from(body?.querySelectorAll('td') ?? []);
        const dashes = cells.filter((cell) => cell.textContent?.trim() === '—');

        expect(dashes).toHaveLength(4);
        expect(cells.some((cell) => cell.textContent?.trim() === '0.00')).toBe(
            false,
        );
    });

    it('shows the account and its closing figure', () => {
        renderPage([row()]);

        const table = screen.getByRole('table');
        expect(within(table).getByText('1100')).toBeInTheDocument();
        expect(within(table).getByText('Cash on Hand')).toBeInTheDocument();
        expect(within(table).getAllByText('5,000.00').length).toBeGreaterThan(
            0,
        );
    });

    it('explains an empty range rather than showing a bare table', () => {
        renderPage([], {
            period_debit_centavos: 0,
            period_credit_centavos: 0,
            closing_debit_centavos: 0,
            closing_credit_centavos: 0,
        });

        expect(
            screen.getByText(/No account carried a balance or moved/),
        ).toBeInTheDocument();
    });
});
