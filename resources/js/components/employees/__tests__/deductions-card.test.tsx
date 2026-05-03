import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DeductionsCard } from '@/components/employees/deductions-card';
import type { DeductionTypeRef, EmployeeDeductionRow } from '@/types';

function makeFixedDeductionType(
    overrides: Partial<DeductionTypeRef> = {},
): DeductionTypeRef {
    return {
        id: 1,
        code: 'HMO',
        name: 'HMO premium',
        is_taxable: false,
        calc_method: 'fixed',
        source: 'employee',
        ...overrides,
    };
}

function makePercentDeductionType(
    overrides: Partial<DeductionTypeRef> = {},
): DeductionTypeRef {
    return {
        id: 2,
        code: 'COOP',
        name: 'Cooperative savings',
        is_taxable: true,
        calc_method: 'percent',
        source: 'employee',
        ...overrides,
    };
}

function makeRow(
    overrides: Partial<EmployeeDeductionRow>,
): EmployeeDeductionRow {
    return {
        id: 1,
        employee_profile_id: 1,
        deduction_type: makeFixedDeductionType(),
        amount_centavos: 50000,
        percent_basis_points: null,
        schedule: 'every_run',
        effective_from: '2026-01-01',
        effective_to: null,
        notes: null,
        ...overrides,
    };
}

describe('DeductionsCard', () => {
    it('renders the empty state when there are no deductions', () => {
        render(<DeductionsCard deductions={[]} />);

        expect(screen.getByText('No custom deductions')).toBeInTheDocument();
    });

    it('renders a fixed-amount row with formatted PHP money', () => {
        const row = makeRow({
            deduction_type: makeFixedDeductionType(),
            amount_centavos: 123456,
            percent_basis_points: null,
        });

        render(<DeductionsCard deductions={[row]} />);

        expect(screen.getByText('HMO premium')).toBeInTheDocument();
        expect(screen.getByText('HMO')).toBeInTheDocument();
        expect(screen.getByText('₱1,234.56')).toBeInTheDocument();
        // Schedule label
        expect(screen.getByText('Every run')).toBeInTheDocument();
    });

    it('renders a percent row using the basis-points value with two decimals', () => {
        const row = makeRow({
            deduction_type: makePercentDeductionType(),
            amount_centavos: null,
            percent_basis_points: 250,
            schedule: 'monthly_first',
        });

        render(<DeductionsCard deductions={[row]} />);

        expect(screen.getByText('Cooperative savings')).toBeInTheDocument();
        expect(screen.getByText('2.50%')).toBeInTheDocument();
        expect(screen.getByText('Monthly · first run')).toBeInTheDocument();
    });

    it('renders the Trash button when onDelete is provided and fires the callback with the row', () => {
        const onDelete = vi.fn();
        const row = makeRow({});

        render(<DeductionsCard deductions={[row]} onDelete={onDelete} />);

        const trash = screen.getByRole('button', {
            name: /Delete deduction HMO premium/i,
        });
        expect(trash).toBeInTheDocument();

        fireEvent.click(trash);

        expect(onDelete).toHaveBeenCalledTimes(1);
        expect(onDelete).toHaveBeenCalledWith(row);
    });

    it('omits the Trash button when onDelete is undefined', () => {
        const row = makeRow({});

        render(<DeductionsCard deductions={[row]} />);

        expect(
            screen.queryByRole('button', { name: /Delete deduction/i }),
        ).not.toBeInTheDocument();
    });
});
