import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AllowancesCard } from '@/components/employees/allowances-card';
import type { AllowanceRef, EmployeeAllowanceRow } from '@/types';

function makeAllowance(overrides: Partial<AllowanceRef> = {}): AllowanceRef {
    return {
        id: 1,
        code: 'RICE',
        name: 'Rice subsidy',
        is_taxable: false,
        is_de_minimis: true,
        de_minimis_cap_centavos: 200000,
        ...overrides,
    };
}

function makeRow(
    overrides: Partial<EmployeeAllowanceRow> = {},
): EmployeeAllowanceRow {
    return {
        id: 1,
        employee_profile_id: 1,
        allowance: makeAllowance(),
        amount_centavos: 200000,
        schedule: 'every_run',
        effective_from: '2026-01-01',
        effective_to: null,
        notes: null,
        ...overrides,
    };
}

describe('AllowancesCard', () => {
    it('renders the empty state when there are no allowances', () => {
        render(<AllowancesCard allowances={[]} />);

        expect(screen.getByText('No active allowances')).toBeInTheDocument();
    });

    it('renders the De-minimis badge when the allowance is flagged as such', () => {
        const row = makeRow({
            allowance: makeAllowance({ is_de_minimis: true }),
        });

        render(<AllowancesCard allowances={[row]} />);

        expect(screen.getByText('De-minimis')).toBeInTheDocument();
        expect(screen.getByText('Rice subsidy')).toBeInTheDocument();
        expect(screen.getByText('₱2,000.00')).toBeInTheDocument();
    });

    it('hides the De-minimis badge when the allowance is not flagged', () => {
        const row = makeRow({
            allowance: makeAllowance({
                code: 'COMM',
                name: 'Communication allowance',
                is_de_minimis: false,
                de_minimis_cap_centavos: null,
            }),
        });

        render(<AllowancesCard allowances={[row]} />);

        expect(screen.queryByText('De-minimis')).not.toBeInTheDocument();
        expect(screen.getByText('Communication allowance')).toBeInTheDocument();
    });

    it('renders the Trash button when onDelete is provided and fires the callback with the row', () => {
        const onDelete = vi.fn();
        const row = makeRow({});

        render(<AllowancesCard allowances={[row]} onDelete={onDelete} />);

        const trash = screen.getByRole('button', {
            name: /Delete allowance Rice subsidy/i,
        });
        expect(trash).toBeInTheDocument();

        fireEvent.click(trash);

        expect(onDelete).toHaveBeenCalledTimes(1);
        expect(onDelete).toHaveBeenCalledWith(row);
    });

    it('omits the Trash button when onDelete is undefined', () => {
        const row = makeRow({});

        render(<AllowancesCard allowances={[row]} />);

        expect(
            screen.queryByRole('button', { name: /Delete allowance/i }),
        ).not.toBeInTheDocument();
    });
});
