import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AdjustmentEditSheet } from '@/components/employees/adjustment-edit-sheet';
import type { PayrollAdjustmentRow } from '@/types';

/**
 * `useForm` shim — see `deduction-edit-sheet.test.tsx` for the rationale.
 */
const lastSubmit: {
    method: 'post' | 'patch' | null;
    url: string | null;
    data: Record<string, unknown> | null;
} = { method: null, url: null, data: null };

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setData] = useState<T>(initial);

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
                setDefaults: vi.fn(),
                clearErrors: vi.fn(),
                reset: vi.fn(),
                transform: vi.fn(),
                post: vi.fn((url: string) => {
                    lastSubmit.method = 'post';
                    lastSubmit.url = url;
                    lastSubmit.data = { ...data };
                }),
                patch: vi.fn((url: string) => {
                    lastSubmit.method = 'patch';
                    lastSubmit.url = url;
                    lastSubmit.data = { ...data };
                }),
            };
        },
    };
});

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

const TAXABLE_ADDITION: PayrollAdjustmentRow = {
    id: 5,
    employee_profile_id: 1,
    kind: 'addition',
    is_taxable: true,
    amount_centavos: 500000,
    applies_on: '2026-05-15',
    label: 'Performance bonus',
    reason: 'Q2 stretch goal',
};

function renderSheet(
    overrides: Partial<{
        adjustment: PayrollAdjustmentRow | undefined;
        lmsStaffId: number;
    }> = {},
) {
    return render(
        <AdjustmentEditSheet
            open
            onOpenChange={vi.fn()}
            lmsStaffId={overrides.lmsStaffId ?? 42}
            adjustment={overrides.adjustment}
        />,
    );
}

describe('AdjustmentEditSheet', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        lastSubmit.method = null;
        lastSubmit.url = null;
        lastSubmit.data = null;
    });

    it('renders both Addition and Deduction options for the kind select', () => {
        renderSheet();

        // Open the Radix Select to expose its options in the DOM.
        fireEvent.click(screen.getByRole('combobox', { name: /kind/i }));

        expect(screen.getByRole('option', { name: 'Addition' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Deduction' })).toBeInTheDocument();
    });

    it('keeps is_taxable independent of kind — a non-taxable addition is allowed', () => {
        // Seed an addition row that is explicitly NOT taxable. The form must
        // mirror the row state without coercing the toggle to track the kind.
        renderSheet({
            adjustment: {
                ...TAXABLE_ADDITION,
                kind: 'addition',
                is_taxable: false,
            },
        });

        const taxableSwitch = screen.getByRole('switch', { name: /taxable/i });
        expect(taxableSwitch).toHaveAttribute('aria-checked', 'false');
    });

    it('keeps is_taxable independent of kind — a taxable deduction is allowed', () => {
        renderSheet({
            adjustment: {
                ...TAXABLE_ADDITION,
                kind: 'deduction',
                is_taxable: true,
                label: 'Wage garnishment',
            },
        });

        const taxableSwitch = screen.getByRole('switch', { name: /taxable/i });
        expect(taxableSwitch).toHaveAttribute('aria-checked', 'true');
    });

    it('converts a peso decimal amount input into integer centavos in the form data', () => {
        renderSheet({ adjustment: TAXABLE_ADDITION });

        const amount = screen.getByLabelText('Amount');
        fireEvent.change(amount, { target: { value: '750.25' } });

        fireEvent.submit(screen.getByRole('button', { name: 'Save changes' }));

        expect(lastSubmit.data).not.toBeNull();
        expect(lastSubmit.data?.amount_centavos).toBe(75025);
    });

    it('submits to the create URL when in create mode', () => {
        renderSheet({ lmsStaffId: 42, adjustment: undefined });

        fireEvent.submit(
            screen.getByRole('button', { name: 'Add adjustment' }),
        );

        expect(lastSubmit.method).toBe('post');
        expect(lastSubmit.url).toBe('/employees/42/adjustments');
    });

    it('submits to the update URL when in edit mode', () => {
        renderSheet({ lmsStaffId: 42, adjustment: TAXABLE_ADDITION });

        fireEvent.submit(screen.getByRole('button', { name: 'Save changes' }));

        expect(lastSubmit.method).toBe('patch');
        expect(lastSubmit.url).toBe('/employees/42/adjustments/5');
    });
});
