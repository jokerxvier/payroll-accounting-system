import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { LoansCard } from '@/components/employees/loans-card';
import type { EmployeeLoanRow } from '@/types';

function makeLoan(overrides: Partial<EmployeeLoanRow> = {}): EmployeeLoanRow {
    return {
        id: 1,
        employee_profile_id: 1,
        code: 'SSS-2026-001',
        principal_centavos: 10_000_000,
        outstanding_balance_centavos: 2_500_000,
        monthly_amortization_centavos: 250_000,
        schedule: 'monthly_first',
        started_on: '2026-01-15',
        closed_on: null,
        notes: null,
        ...overrides,
    };
}

describe('LoansCard', () => {
    it('renders the empty state when there are no loans', () => {
        render(<LoansCard loans={[]} />);

        expect(screen.getByText('No loans on file')).toBeInTheDocument();
    });

    it('computes percent-paid for the progress bar (75% when 75k of 100k paid)', () => {
        // principal 100,000.00 (10_000_000 centavos), outstanding 25,000.00
        // → paid 75,000.00 → 75%.
        const loan = makeLoan({
            principal_centavos: 10_000_000,
            outstanding_balance_centavos: 2_500_000,
        });

        render(<LoansCard loans={[loan]} />);

        const progressbar = screen.getByRole('progressbar', {
            name: 'Loan progress',
        });
        expect(progressbar).toHaveAttribute('aria-valuenow', '75');
        expect(screen.getByText('75%')).toBeInTheDocument();
    });

    it('shows the Open badge for an open loan and Closed for a closed one', () => {
        const open = makeLoan({ id: 1, closed_on: null });
        const closed = makeLoan({
            id: 2,
            code: 'HRDept-Loan-7',
            outstanding_balance_centavos: 0,
            closed_on: '2026-04-30',
        });

        render(<LoansCard loans={[open, closed]} />);

        expect(screen.getByText('Open')).toBeInTheDocument();
        expect(screen.getByText('Closed')).toBeInTheDocument();
    });

    it('clamps to 0% when principal is zero (avoids divide-by-zero)', () => {
        const loan = makeLoan({
            principal_centavos: 0,
            outstanding_balance_centavos: 0,
        });

        render(<LoansCard loans={[loan]} />);

        const progressbar = screen.getByRole('progressbar', {
            name: 'Loan progress',
        });
        expect(progressbar).toHaveAttribute('aria-valuenow', '0');
    });

    it('renders the Trash button when onDelete is provided and fires the callback with the loan', () => {
        const onDelete = vi.fn();
        const loan = makeLoan({});

        render(<LoansCard loans={[loan]} onDelete={onDelete} />);

        const trash = screen.getByRole('button', {
            name: /Delete loan SSS-2026-001/i,
        });
        expect(trash).toBeInTheDocument();

        fireEvent.click(trash);

        expect(onDelete).toHaveBeenCalledTimes(1);
        expect(onDelete).toHaveBeenCalledWith(loan);
    });

    it('omits the Trash button when onDelete is undefined', () => {
        const loan = makeLoan({});

        render(<LoansCard loans={[loan]} />);

        expect(
            screen.queryByRole('button', { name: /Delete loan/i }),
        ).not.toBeInTheDocument();
    });
});
