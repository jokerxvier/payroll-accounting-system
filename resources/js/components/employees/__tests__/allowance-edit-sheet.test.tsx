import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AllowanceEditSheet } from '@/components/employees/allowance-edit-sheet';
import type { AllowanceRef, EmployeeAllowanceRow } from '@/types';

/**
 * Mirror the `useForm` shim from the deduction sheet test — see that file's
 * header comment for the rationale.
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

const RICE_DE_MINIMIS: AllowanceRef = {
    id: 1,
    code: 'RICE',
    name: 'Rice subsidy',
    is_taxable: false,
    is_de_minimis: true,
    de_minimis_cap_centavos: 200000,
};

const COMMS_REGULAR: AllowanceRef = {
    id: 2,
    code: 'COMM',
    name: 'Communication allowance',
    is_taxable: true,
    is_de_minimis: false,
    de_minimis_cap_centavos: null,
};

function renderSheet(
    overrides: Partial<{
        allowance: EmployeeAllowanceRow | undefined;
        lmsStaffId: number;
        allowanceOptions: AllowanceRef[];
    }> = {},
) {
    return render(
        <AllowanceEditSheet
            open
            onOpenChange={vi.fn()}
            lmsStaffId={overrides.lmsStaffId ?? 42}
            allowance={overrides.allowance}
            allowanceOptions={
                overrides.allowanceOptions ?? [RICE_DE_MINIMIS, COMMS_REGULAR]
            }
        />,
    );
}

describe('AllowanceEditSheet', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        lastSubmit.method = null;
        lastSubmit.url = null;
        lastSubmit.data = null;
    });

    it('renders the De-minimis badge inside the option list when an allowance is flagged', () => {
        renderSheet();

        // Open the Radix Select to expose its option list.
        fireEvent.click(screen.getByRole('combobox', { name: /allowance/i }));

        // The De-minimis badge sits next to RICE in the option list.
        expect(screen.getByText('De-minimis')).toBeInTheDocument();
    });

    it('converts a peso decimal amount input into integer centavos in the form data', () => {
        renderSheet({
            allowance: {
                id: 9,
                employee_profile_id: 1,
                allowance: RICE_DE_MINIMIS,
                amount_centavos: 200000,
                schedule: 'every_run',
                effective_from: '2026-01-01',
                effective_to: null,
                notes: null,
            },
        });

        const amount = screen.getByLabelText('Amount');
        fireEvent.change(amount, { target: { value: '1500.50' } });

        // Submit and inspect the data captured by the post mock.
        fireEvent.submit(screen.getByRole('button', { name: 'Save changes' }));

        expect(lastSubmit.data).not.toBeNull();
        expect(lastSubmit.data?.amount_centavos).toBe(150050);
    });

    it('pre-populates fields from the allowance prop in edit mode', () => {
        renderSheet({
            allowance: {
                id: 12,
                employee_profile_id: 1,
                allowance: RICE_DE_MINIMIS,
                amount_centavos: 250000,
                schedule: 'monthly_first',
                effective_from: '2026-03-15',
                effective_to: null,
                notes: 'rice subsidy seed',
            },
        });

        // 250_000 centavos → "2500.00".
        expect(screen.getByLabelText('Amount')).toHaveValue('2500.00');
        expect(screen.getByLabelText('Notes')).toHaveValue('rice subsidy seed');
    });

    it('submits to the create URL when in create mode', () => {
        renderSheet({ lmsStaffId: 42, allowance: undefined });

        fireEvent.submit(screen.getByRole('button', { name: 'Add allowance' }));

        expect(lastSubmit.method).toBe('post');
        expect(lastSubmit.url).toBe('/employees/42/allowances');
    });

    it('submits to the update URL when in edit mode', () => {
        renderSheet({
            lmsStaffId: 42,
            allowance: {
                id: 88,
                employee_profile_id: 1,
                allowance: RICE_DE_MINIMIS,
                amount_centavos: 200000,
                schedule: 'every_run',
                effective_from: '2026-01-01',
                effective_to: null,
                notes: null,
            },
        });

        fireEvent.submit(screen.getByRole('button', { name: 'Save changes' }));

        expect(lastSubmit.method).toBe('patch');
        expect(lastSubmit.url).toBe('/employees/42/allowances/88');
    });
});
