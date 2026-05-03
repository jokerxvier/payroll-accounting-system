import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { LoanEditSheet } from '@/components/employees/loan-edit-sheet';
import type { EmployeeLoanRow } from '@/types';

/**
 * `useForm` shim — see `deduction-edit-sheet.test.tsx` for the rationale.
 *
 * We additionally capture the most recent `transform` callback so we can
 * verify the patch payload only carries the four fields the FormRequest
 * accepts (code, monthly_amortization_centavos, schedule, notes) — i.e. it
 * never leaks principal/outstanding/lock_version on the wire.
 */
const lastSubmit: {
    method: 'post' | 'patch' | null;
    url: string | null;
    transformedData: Record<string, unknown> | null;
} = { method: null, url: null, transformedData: null };

let lastTransform: ((data: Record<string, unknown>) => Record<string, unknown>) | null =
    null;

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
                transform: vi.fn(
                    (
                        cb: (
                            input: Record<string, unknown>,
                        ) => Record<string, unknown>,
                    ) => {
                        lastTransform = cb;
                    },
                ),
                post: vi.fn((url: string) => {
                    lastSubmit.method = 'post';
                    lastSubmit.url = url;
                    lastSubmit.transformedData = lastTransform
                        ? lastTransform({ ...data })
                        : { ...data };
                }),
                patch: vi.fn((url: string) => {
                    lastSubmit.method = 'patch';
                    lastSubmit.url = url;
                    lastSubmit.transformedData = lastTransform
                        ? lastTransform({ ...data })
                        : { ...data };
                }),
            };
        },
    };
});

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

const SAMPLE_LOAN: EmployeeLoanRow = {
    id: 7,
    employee_profile_id: 1,
    code: 'SSS-2026-001',
    principal_centavos: 10_000_000,
    outstanding_balance_centavos: 2_500_000,
    monthly_amortization_centavos: 250_000,
    schedule: 'monthly_first',
    started_on: '2026-01-15',
    closed_on: null,
    notes: null,
};

function renderSheet(
    overrides: Partial<{
        loan: EmployeeLoanRow | undefined;
        lmsStaffId: number;
    }> = {},
) {
    return render(
        <LoanEditSheet
            open
            onOpenChange={vi.fn()}
            lmsStaffId={overrides.lmsStaffId ?? 42}
            loan={overrides.loan}
        />,
    );
}

describe('LoanEditSheet', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        lastSubmit.method = null;
        lastSubmit.url = null;
        lastSubmit.transformedData = null;
        lastTransform = null;
    });

    it('renders all input fields in create mode (code, principal, amortization, started_on)', () => {
        renderSheet({ loan: undefined });

        expect(screen.getByLabelText('Loan code')).toBeInTheDocument();
        expect(screen.getByLabelText('Principal')).toBeInTheDocument();
        expect(
            screen.getByLabelText('Monthly amortization'),
        ).toBeInTheDocument();
        // Started on is a DatePicker with id="started_on" — getByLabelText
        // resolves the trigger button via the <Label htmlFor=…> pairing.
        expect(screen.getByLabelText('Started on')).toBeInTheDocument();
        expect(screen.getByLabelText('Notes')).toBeInTheDocument();
    });

    it('does NOT render an editable Principal input in edit mode (surfaced read-only instead)', () => {
        renderSheet({ loan: SAMPLE_LOAN });

        // The editable input would carry id="principal_centavos"; the
        // read-only context only renders <Money> inside a <dd>.
        expect(
            screen.queryByLabelText('Principal'),
        ).not.toBeInTheDocument();

        // The read-only context block is present.
        expect(screen.getByText('Read-only')).toBeInTheDocument();
        // Both Principal and Outstanding read-only labels are visible.
        expect(
            screen.getAllByText(/Principal/).length,
        ).toBeGreaterThanOrEqual(1);
        expect(screen.getByText(/Outstanding/)).toBeInTheDocument();
    });

    it('does NOT render an editable Started on field in edit mode', () => {
        renderSheet({ loan: SAMPLE_LOAN });

        // The DatePicker would expose id="started_on" via getByLabelText.
        expect(
            screen.queryByLabelText('Started on'),
        ).not.toBeInTheDocument();

        // The started_on date is still surfaced in the read-only block.
        expect(screen.getByText('Started on')).toBeInTheDocument();
    });

    it('omits principal_centavos / outstanding_balance_centavos / lock_version from the edit-mode patch payload', () => {
        renderSheet({ loan: SAMPLE_LOAN });

        fireEvent.submit(screen.getByRole('button', { name: 'Save changes' }));

        expect(lastSubmit.method).toBe('patch');
        expect(lastSubmit.transformedData).not.toBeNull();
        const payload = lastSubmit.transformedData as Record<string, unknown>;

        // Only the four fields the FormRequest accepts.
        expect(Object.keys(payload).sort()).toEqual(
            [
                'code',
                'monthly_amortization_centavos',
                'notes',
                'schedule',
            ].sort(),
        );

        // Defense in depth: the dangerous keys must not be present.
        expect(payload).not.toHaveProperty('principal_centavos');
        expect(payload).not.toHaveProperty('outstanding_balance_centavos');
        expect(payload).not.toHaveProperty('lock_version');
        expect(payload).not.toHaveProperty('started_on');
    });

    it('submits to the correct create URL in create mode', () => {
        renderSheet({ lmsStaffId: 42, loan: undefined });

        fireEvent.submit(screen.getByRole('button', { name: 'Add loan' }));

        expect(lastSubmit.method).toBe('post');
        expect(lastSubmit.url).toBe('/employees/42/loans');
    });

    it('submits to the correct update URL in edit mode', () => {
        renderSheet({ lmsStaffId: 42, loan: SAMPLE_LOAN });

        fireEvent.submit(screen.getByRole('button', { name: 'Save changes' }));

        expect(lastSubmit.method).toBe('patch');
        expect(lastSubmit.url).toBe('/employees/42/loans/7');
    });
});
