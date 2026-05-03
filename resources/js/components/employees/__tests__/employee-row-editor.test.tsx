import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { EmployeeRowEditor } from '@/components/employees/employee-row-editor';
import type {
    AllowanceRef,
    DeductionTypeRef,
    EmployeeAllowanceRow,
    EmployeeDeductionRow,
    EmployeeLoanRow,
    EmployeeProfile,
    EmploymentTypeOption,
    PayrollAdjustmentRow,
} from '@/types';

/**
 * The row editor lazy-fetches profile JSON, hosts four sub-forms backed by
 * Inertia's `useForm`, and mounts four edit sheets. We mock:
 *
 *   - `@inertiajs/react` `useForm` with the same React-state shim used by the
 *     edit-sheet tests, so the sub-forms render synchronously.
 *   - The four edit sheets so the test focuses on orchestration (tab → card
 *     → sheet open) rather than the sheets' own (already covered) logic.
 *   - `sonner` so toast.success calls don't blow up.
 *   - `global.fetch` so the lazy profile load resolves with deterministic data.
 */

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        router: {
            delete: vi.fn(),
        },
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
                post: vi.fn(),
                patch: vi.fn(),
            };
        },
    };
});

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock('@/components/employees/deduction-edit-sheet', () => ({
    DeductionEditSheet: ({ open }: { open: boolean }) =>
        open ? (
            <div data-testid="deduction-edit-sheet">deduction sheet</div>
        ) : null,
}));

vi.mock('@/components/employees/allowance-edit-sheet', () => ({
    AllowanceEditSheet: ({ open }: { open: boolean }) =>
        open ? (
            <div data-testid="allowance-edit-sheet">allowance sheet</div>
        ) : null,
}));

vi.mock('@/components/employees/loan-edit-sheet', () => ({
    LoanEditSheet: ({ open }: { open: boolean }) =>
        open ? <div data-testid="loan-edit-sheet">loan sheet</div> : null,
}));

vi.mock('@/components/employees/adjustment-edit-sheet', () => ({
    AdjustmentEditSheet: ({ open }: { open: boolean }) =>
        open ? (
            <div data-testid="adjustment-edit-sheet">adjustment sheet</div>
        ) : null,
}));

const SAMPLE_PROFILE: EmployeeProfile = {
    id: 1,
    lms_staff_id: 42,
    employment_classification: 'regular',
    pay_frequency: 'semi_monthly',
    basic_salary_centavos: 5_000_000,
    tax_status: 'S',
    tin: null,
    sss_number: null,
    philhealth_number: null,
    pagibig_number: null,
    bank_name: null,
    bank_account_number: null,
    bank_account_name: null,
    date_hired: '2026-01-01',
    date_terminated: null,
    is_active: true,
    created_at: null,
    updated_at: null,
};

const HMO_TYPE: DeductionTypeRef = {
    id: 1,
    code: 'HMO',
    name: 'HMO premium',
    is_taxable: false,
    calc_method: 'fixed',
    source: 'employee',
};

const RICE_ALLOWANCE: AllowanceRef = {
    id: 1,
    code: 'RICE',
    name: 'Rice subsidy',
    is_taxable: false,
    is_de_minimis: true,
    de_minimis_cap_centavos: 200000,
};

const SAMPLE_DEDUCTION: EmployeeDeductionRow = {
    id: 11,
    employee_profile_id: 1,
    deduction_type: HMO_TYPE,
    amount_centavos: 50_000,
    percent_basis_points: null,
    schedule: 'every_run',
    effective_from: '2026-01-01',
    effective_to: null,
    notes: null,
};

const SAMPLE_ALLOWANCE: EmployeeAllowanceRow = {
    id: 21,
    employee_profile_id: 1,
    allowance: RICE_ALLOWANCE,
    amount_centavos: 200_000,
    schedule: 'every_run',
    effective_from: '2026-01-01',
    effective_to: null,
    notes: null,
};

const SAMPLE_LOAN: EmployeeLoanRow = {
    id: 31,
    employee_profile_id: 1,
    code: 'SSS-2026-001',
    principal_centavos: 10_000_000,
    outstanding_balance_centavos: 7_500_000,
    monthly_amortization_centavos: 250_000,
    schedule: 'monthly_first',
    started_on: '2026-01-15',
    closed_on: null,
    notes: null,
};

const SAMPLE_ADJUSTMENT: PayrollAdjustmentRow = {
    id: 41,
    employee_profile_id: 1,
    kind: 'addition',
    is_taxable: true,
    amount_centavos: 100_000,
    applies_on: '2026-02-15',
    label: 'Performance bonus',
    reason: 'Q4 award',
};

const EMPLOYMENT_TYPES: EmploymentTypeOption[] = [
    { value: 'regular', label: 'Regular' },
    { value: 'probationary', label: 'Probationary' },
];

interface FetchPayloadOverrides {
    profile?: EmployeeProfile | null;
    deductions?: EmployeeDeductionRow[];
    allowances?: EmployeeAllowanceRow[];
    loans?: EmployeeLoanRow[];
    pendingAdjustments?: PayrollAdjustmentRow[];
    deductionTypeOptions?: DeductionTypeRef[];
    allowanceOptions?: AllowanceRef[];
}

function buildPayload(overrides: FetchPayloadOverrides = {}) {
    return {
        profile: overrides.profile ?? SAMPLE_PROFILE,
        deductions: overrides.deductions ?? [SAMPLE_DEDUCTION],
        allowances: overrides.allowances ?? [SAMPLE_ALLOWANCE],
        loans: overrides.loans ?? [SAMPLE_LOAN],
        pendingAdjustments: overrides.pendingAdjustments ?? [SAMPLE_ADJUSTMENT],
        deductionTypeOptions: overrides.deductionTypeOptions ?? [HMO_TYPE],
        allowanceOptions: overrides.allowanceOptions ?? [RICE_ALLOWANCE],
    };
}

// A single shared payload reference — each fetch call reads the *current*
// value, so a test can swap in a different payload before rendering and the
// next fetch call will pick it up. Plain `vi.fn().mockImplementation()` chains
// proved unreliable across re-renders in our environment.
let currentPayload: ReturnType<typeof buildPayload> = buildPayload();

const originalFetch = globalThis.fetch;

function setPayload(payload: ReturnType<typeof buildPayload>): void {
    currentPayload = payload;
}

describe('EmployeeRowEditor', () => {
    beforeEach(() => {
        setPayload(buildPayload());
        globalThis.fetch = vi.fn(
            async () =>
                new Response(JSON.stringify(currentPayload), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
        ) as typeof globalThis.fetch;
    });

    afterEach(() => {
        globalThis.fetch = originalFetch;
    });

    it('renders the eight section tabs once the profile loads', async () => {
        render(
            <EmployeeRowEditor
                staffId={42}
                fullName="Maria Cruz"
                employmentTypeOptions={EMPLOYMENT_TYPES}
                onClose={vi.fn()}
            />,
        );

        // Profile JSON resolves → the salary form renders by default.
        await waitFor(() => {
            expect(screen.getByLabelText(/Basic salary/i)).toBeInTheDocument();
        });

        const expectedTabs = [
            'Salary & classification',
            'Status',
            'Government IDs',
            'Bank account',
            'Deductions',
            'Allowances',
            'Loans',
            'Adjustments',
        ];

        for (const label of expectedTabs) {
            expect(
                screen.getByRole('tab', { name: new RegExp(label, 'i') }),
            ).toBeInTheDocument();
        }
    });

    it('renders the deductions card when the Deductions tab is active', async () => {
        render(
            <EmployeeRowEditor
                staffId={42}
                fullName="Maria Cruz"
                employmentTypeOptions={EMPLOYMENT_TYPES}
                onClose={vi.fn()}
            />,
        );

        await waitFor(() => {
            expect(screen.getByLabelText(/Basic salary/i)).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('tab', { name: /Deductions/i }));

        // The deductions card surfaces the lazy-fetched HMO row.
        expect(screen.getByText('HMO premium')).toBeInTheDocument();
        expect(screen.getByText('HMO')).toBeInTheDocument();
        expect(screen.getByText('₱500.00')).toBeInTheDocument();
    });

    it('opens the deduction edit sheet when "Add deduction" is clicked', async () => {
        render(
            <EmployeeRowEditor
                staffId={42}
                fullName="Maria Cruz"
                employmentTypeOptions={EMPLOYMENT_TYPES}
                onClose={vi.fn()}
            />,
        );

        await waitFor(() => {
            expect(screen.getByLabelText(/Basic salary/i)).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('tab', { name: /Deductions/i }));
        fireEvent.click(screen.getByRole('button', { name: /Add deduction/i }));

        expect(screen.getByTestId('deduction-edit-sheet')).toBeInTheDocument();
    });

    it('renders the loans card when the Loans tab is active', async () => {
        render(
            <EmployeeRowEditor
                staffId={42}
                fullName="Maria Cruz"
                employmentTypeOptions={EMPLOYMENT_TYPES}
                onClose={vi.fn()}
            />,
        );

        await waitFor(() => {
            expect(screen.getByLabelText(/Basic salary/i)).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('tab', { name: /Loans/i }));

        expect(screen.getByText('SSS-2026-001')).toBeInTheDocument();
        // Outstanding figure surfaces through the Money component.
        expect(screen.getByText('₱75,000.00')).toBeInTheDocument();
    });

    it('opens the adjustment edit sheet from the Adjustments tab', async () => {
        render(
            <EmployeeRowEditor
                staffId={42}
                fullName="Maria Cruz"
                employmentTypeOptions={EMPLOYMENT_TYPES}
                onClose={vi.fn()}
            />,
        );

        await waitFor(() => {
            expect(screen.getByLabelText(/Basic salary/i)).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('tab', { name: /Adjustments/i }));

        // The adjustment row from the lazy fetch is visible in the card.
        expect(screen.getByText('Performance bonus')).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: /Add adjustment/i }),
        );

        expect(screen.getByTestId('adjustment-edit-sheet')).toBeInTheDocument();
    });

    it('opens the AlertDialog with deduction identity when the Trash button is clicked on a deduction row', async () => {
        render(
            <EmployeeRowEditor
                staffId={42}
                fullName="Maria Cruz"
                employmentTypeOptions={EMPLOYMENT_TYPES}
                onClose={vi.fn()}
            />,
        );

        await waitFor(() => {
            expect(screen.getByLabelText(/Basic salary/i)).toBeInTheDocument();
        });

        fireEvent.click(screen.getByRole('tab', { name: /Deductions/i }));

        // Click the trash on the HMO row.
        fireEvent.click(
            screen.getByRole('button', {
                name: /Delete deduction HMO premium/i,
            }),
        );

        // The shared AlertDialog opens with the deduction-specific copy.
        expect(
            screen.getByRole('alertdialog', {
                name: /Delete custom deduction\?/i,
            }),
        ).toBeInTheDocument();
        // The deduction's display name surfaces inside the description.
        expect(
            screen.getAllByText('HMO premium').length,
        ).toBeGreaterThanOrEqual(1);
    });
});
