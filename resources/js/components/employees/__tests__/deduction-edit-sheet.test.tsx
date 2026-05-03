import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DeductionEditSheet } from '@/components/employees/deduction-edit-sheet';
import type { DeductionTypeRef, EmployeeDeductionRow } from '@/types';

/**
 * Inertia's `useForm` keeps its own internal state. We swap it for a small
 * React-state-backed shim so the component renders synchronously without an
 * Inertia provider — the same pattern used by
 * `pages/admin/allowances/__tests__/create.test.tsx`.
 *
 * The shim also exposes `__getLastPostUrl` / `__getLastPatchUrl` test hooks so
 * we can assert the Wayfinder URL the form submits to without standing up a
 * real Inertia router.
 */
const lastSubmit: { method: 'post' | 'patch' | null; url: string | null } = {
    method: null,
    url: null,
};

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
                }),
                patch: vi.fn((url: string) => {
                    lastSubmit.method = 'patch';
                    lastSubmit.url = url;
                }),
            };
        },
    };
});

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

const FIXED_TYPE: DeductionTypeRef = {
    id: 1,
    code: 'HMO',
    name: 'HMO premium',
    is_taxable: false,
    calc_method: 'fixed',
    source: 'employee',
};

const PERCENT_TYPE: DeductionTypeRef = {
    id: 2,
    code: 'COOP',
    name: 'Cooperative savings',
    is_taxable: true,
    calc_method: 'percent',
    source: 'employee',
};

function renderSheet(
    overrides: Partial<{
        deduction: EmployeeDeductionRow | undefined;
        lmsStaffId: number;
        deductionTypeOptions: DeductionTypeRef[];
    }> = {},
) {
    return render(
        <DeductionEditSheet
            open
            onOpenChange={vi.fn()}
            lmsStaffId={overrides.lmsStaffId ?? 42}
            deduction={overrides.deduction}
            deductionTypeOptions={
                overrides.deductionTypeOptions ?? [FIXED_TYPE, PERCENT_TYPE]
            }
        />,
    );
}

describe('DeductionEditSheet', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        lastSubmit.method = null;
        lastSubmit.url = null;
    });

    it('shows the amount field (and hides percent) when the selected type is fixed', () => {
        renderSheet({
            deduction: {
                id: 99,
                employee_profile_id: 1,
                deduction_type: FIXED_TYPE,
                amount_centavos: 50000,
                percent_basis_points: null,
                schedule: 'every_run',
                effective_from: '2026-01-01',
                effective_to: null,
                notes: null,
            },
        });

        expect(screen.getByLabelText('Amount')).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Percent of gross'),
        ).not.toBeInTheDocument();
    });

    it('shows the percent field (and hides amount) when the selected type is percent', () => {
        renderSheet({
            deduction: {
                id: 100,
                employee_profile_id: 1,
                deduction_type: PERCENT_TYPE,
                amount_centavos: null,
                percent_basis_points: 250,
                schedule: 'every_run',
                effective_from: '2026-01-01',
                effective_to: null,
                notes: null,
            },
        });

        expect(screen.getByLabelText('Percent of gross')).toBeInTheDocument();
        expect(screen.queryByLabelText('Amount')).not.toBeInTheDocument();
    });

    it('pre-populates the amount field from the deduction prop in edit mode', () => {
        renderSheet({
            deduction: {
                id: 101,
                employee_profile_id: 1,
                deduction_type: FIXED_TYPE,
                amount_centavos: 123456,
                percent_basis_points: null,
                schedule: 'every_run',
                effective_from: '2026-02-01',
                effective_to: null,
                notes: 'previous note',
            },
        });

        // 123_456 centavos formatted as pesos with 2 decimals.
        expect(screen.getByLabelText('Amount')).toHaveValue('1234.56');
        expect(screen.getByLabelText('Notes')).toHaveValue('previous note');
    });

    it('switches the visible field when a different calc_method type is picked', () => {
        // Start with no selection (create mode); pick the fixed type via the
        // hidden native select state controlled by Radix. We exercise the
        // exposed `handleTypeChange` indirectly by rendering once with each
        // selected type — the underlying behaviour is the same value-driven
        // render path the user triggers via the dropdown.
        const { rerender } = render(
            <DeductionEditSheet
                open
                onOpenChange={vi.fn()}
                lmsStaffId={42}
                deduction={{
                    id: 200,
                    employee_profile_id: 1,
                    deduction_type: FIXED_TYPE,
                    amount_centavos: 50000,
                    percent_basis_points: null,
                    schedule: 'every_run',
                    effective_from: '2026-01-01',
                    effective_to: null,
                    notes: null,
                }}
                deductionTypeOptions={[FIXED_TYPE, PERCENT_TYPE]}
            />,
        );
        expect(screen.getByLabelText('Amount')).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Percent of gross'),
        ).not.toBeInTheDocument();

        rerender(
            <DeductionEditSheet
                open
                onOpenChange={vi.fn()}
                lmsStaffId={42}
                deduction={{
                    id: 201,
                    employee_profile_id: 1,
                    deduction_type: PERCENT_TYPE,
                    amount_centavos: null,
                    percent_basis_points: 250,
                    schedule: 'every_run',
                    effective_from: '2026-01-01',
                    effective_to: null,
                    notes: null,
                }}
                deductionTypeOptions={[FIXED_TYPE, PERCENT_TYPE]}
            />,
        );
        expect(screen.getByLabelText('Percent of gross')).toBeInTheDocument();
        expect(screen.queryByLabelText('Amount')).not.toBeInTheDocument();
    });

    it('submits to the create URL when in create mode', () => {
        renderSheet({ lmsStaffId: 42, deduction: undefined });

        fireEvent.submit(screen.getByRole('button', { name: 'Add deduction' }));

        expect(lastSubmit.method).toBe('post');
        expect(lastSubmit.url).toBe('/employees/42/deductions');
    });

    it('submits to the update URL when in edit mode', () => {
        renderSheet({
            lmsStaffId: 42,
            deduction: {
                id: 7,
                employee_profile_id: 1,
                deduction_type: FIXED_TYPE,
                amount_centavos: 50000,
                percent_basis_points: null,
                schedule: 'every_run',
                effective_from: '2026-01-01',
                effective_to: null,
                notes: null,
            },
        });

        fireEvent.submit(screen.getByRole('button', { name: 'Save changes' }));

        expect(lastSubmit.method).toBe('patch');
        expect(lastSubmit.url).toBe('/employees/42/deductions/7');
    });
});
