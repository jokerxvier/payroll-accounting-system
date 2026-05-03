import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AdjustmentsCard } from '@/components/employees/adjustments-card';
import type { PayrollAdjustmentRow } from '@/types';

function makeAdjustment(
    overrides: Partial<PayrollAdjustmentRow> = {},
): PayrollAdjustmentRow {
    return {
        id: 1,
        employee_profile_id: 1,
        kind: 'addition',
        is_taxable: true,
        amount_centavos: 500000,
        applies_on: '2026-05-15',
        label: 'Performance bonus',
        reason: 'Q2 stretch goal achieved',
        ...overrides,
    };
}

describe('AdjustmentsCard', () => {
    it('renders the empty state when there are no adjustments', () => {
        render(<AdjustmentsCard adjustments={[]} />);

        expect(screen.getByText('No adjustments')).toBeInTheDocument();
    });

    it('renders an Addition badge for kind=addition with success styling', () => {
        const row = makeAdjustment({ kind: 'addition' });

        render(<AdjustmentsCard adjustments={[row]} />);

        const badge = screen.getByText('Addition');
        expect(badge).toBeInTheDocument();
        expect(badge.className).toContain('text-success');
    });

    it('renders a Deduction badge for kind=deduction using the destructive variant', () => {
        const row = makeAdjustment({
            id: 2,
            kind: 'deduction',
            label: 'Cash advance recovery',
            reason: null,
        });

        render(<AdjustmentsCard adjustments={[row]} />);

        const badge = screen.getByText('Deduction');
        expect(badge).toBeInTheDocument();
        // Destructive variant maps to bg-destructive (see badge.tsx).
        expect(badge.className).toContain('bg-destructive');
    });

    it('renders the row label and a Taxable badge when is_taxable=true', () => {
        const row = makeAdjustment({ is_taxable: true });

        render(<AdjustmentsCard adjustments={[row]} />);

        expect(screen.getByText('Performance bonus')).toBeInTheDocument();
        expect(screen.getByText('Taxable')).toBeInTheDocument();
    });

    it('renders the Trash button when onDelete is provided and fires the callback with the row', () => {
        const onDelete = vi.fn();
        const row = makeAdjustment({});

        render(<AdjustmentsCard adjustments={[row]} onDelete={onDelete} />);

        const trash = screen.getByRole('button', {
            name: /Delete adjustment Performance bonus/i,
        });
        expect(trash).toBeInTheDocument();

        fireEvent.click(trash);

        expect(onDelete).toHaveBeenCalledTimes(1);
        expect(onDelete).toHaveBeenCalledWith(row);
    });

    it('omits the Trash button when onDelete is undefined', () => {
        const row = makeAdjustment({});

        render(<AdjustmentsCard adjustments={[row]} />);

        expect(
            screen.queryByRole('button', { name: /Delete adjustment/i }),
        ).not.toBeInTheDocument();
    });
});
