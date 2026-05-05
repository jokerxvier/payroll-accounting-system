import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EmployeeEditSheet } from '@/components/employees/edit-sheet';
import type {
    AvailableContribution,
    EmployeeDetail,
    EmployeeProfile,
    EmploymentTypeOption,
} from '@/types';

/**
 * Same `useForm` shim used by the sibling edit-sheet tests — see
 * `deduction-edit-sheet.test.tsx` for the rationale. Captures the most recent
 * form payload so tests can assert what gets sent on submit (e.g. that the
 * exemption array is included).
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

const EMPLOYMENT_TYPE_OPTIONS: EmploymentTypeOption[] = [
    { value: 'regular', label: 'Regular' },
    { value: 'probationary', label: 'Probationary' },
    { value: 'contractual', label: 'Contractual' },
    { value: 'part_time', label: 'Part Time' },
];

const AVAILABLE_CONTRIBUTIONS: AvailableContribution[] = [
    { code: 'SSS', label: 'SSS contribution' },
    { code: 'PHILHEALTH', label: 'PhilHealth premium' },
    { code: 'PAGIBIG', label: 'Pag-IBIG contribution' },
    { code: 'BIR_WITHHOLDING', label: 'BIR Withholding Tax' },
];

function buildProfile(
    overrides: Partial<EmployeeProfile> = {},
): EmployeeProfile {
    return {
        id: 1,
        lms_staff_id: 42,
        employment_classification: 'regular',
        pay_frequency: 'semi_monthly',
        basic_salary_centavos: 5_000_000,
        tax_status: 'S',
        tin: '',
        sss_number: '',
        philhealth_number: '',
        pagibig_number: '',
        bank_name: '',
        bank_account_number: '',
        bank_account_name: '',
        date_hired: '2024-01-15',
        date_terminated: null,
        is_active: true,
        exempted_contribution_codes: [],
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

function buildEmployee(profile: EmployeeProfile | null): EmployeeDetail {
    return {
        lms_staff_id: 42,
        staff_no: 'EMP-042',
        full_name: 'Maria Santos',
        email: 'maria@example.com',
        department: { id: 1, name: 'Engineering' },
        designation: { id: 1, title: 'Senior Engineer' },
        role: { id: 4, name: 'Faculty' },
        profile,
    };
}

function renderSheet(
    overrides: Partial<{
        availableContributions: AvailableContribution[];
        profile: EmployeeProfile | null;
    }> = {},
) {
    const profile = 'profile' in overrides ? overrides.profile : buildProfile();

    return render(
        <EmployeeEditSheet
            open
            onOpenChange={vi.fn()}
            employee={buildEmployee(profile ?? null)}
            employmentTypeOptions={EMPLOYMENT_TYPE_OPTIONS}
            availableContributions={
                overrides.availableContributions ?? AVAILABLE_CONTRIBUTIONS
            }
        />,
    );
}

describe('EmployeeEditSheet — statutory exemptions', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        lastSubmit.method = null;
        lastSubmit.url = null;
        lastSubmit.data = null;
    });

    it('renders the exemptions section with one checkbox per available contribution', () => {
        renderSheet();

        expect(
            screen.getByRole('heading', { name: 'Statutory exemptions' }),
        ).toBeInTheDocument();

        for (const contribution of AVAILABLE_CONTRIBUTIONS) {
            // Each checkbox is labelled by the wrapping <label>, which exposes
            // the contribution code as its accessible name.
            const checkbox = screen.getByRole('checkbox', {
                name: new RegExp(contribution.code),
            });
            expect(checkbox).toBeInTheDocument();
            expect(checkbox).not.toBeChecked();
        }
    });

    it('hides the exemptions section when the available contributions list is empty', () => {
        renderSheet({ availableContributions: [] });

        expect(
            screen.queryByRole('heading', { name: 'Statutory exemptions' }),
        ).not.toBeInTheDocument();
    });

    it('reflects pre-existing exemptions from the profile as checked rows', () => {
        renderSheet({
            profile: buildProfile({
                exempted_contribution_codes: ['SSS', 'PHILHEALTH'],
            }),
        });

        expect(screen.getByRole('checkbox', { name: /SSS/ })).toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: /PHILHEALTH/ }),
        ).toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: /PAGIBIG/ }),
        ).not.toBeChecked();
    });

    it('adds a code to the exemption list when a checkbox is toggled on', () => {
        renderSheet();

        fireEvent.click(screen.getByRole('checkbox', { name: /SSS/ }));
        fireEvent.submit(screen.getByRole('button', { name: 'Save changes' }));

        expect(lastSubmit.method).toBe('patch');
        expect(lastSubmit.data?.exempted_contribution_codes).toEqual(['SSS']);
    });

    it('removes a code from the exemption list when its checkbox is toggled off', () => {
        renderSheet({
            profile: buildProfile({
                exempted_contribution_codes: ['SSS', 'PHILHEALTH'],
            }),
        });

        fireEvent.click(screen.getByRole('checkbox', { name: /SSS/ }));
        fireEvent.submit(screen.getByRole('button', { name: 'Save changes' }));

        expect(lastSubmit.data?.exempted_contribution_codes).toEqual([
            'PHILHEALTH',
        ]);
    });

    it('submits the exemption array on the PATCH payload alongside other fields', () => {
        renderSheet();

        fireEvent.click(
            screen.getByRole('checkbox', { name: /BIR_WITHHOLDING/ }),
        );
        fireEvent.submit(screen.getByRole('button', { name: 'Save changes' }));

        expect(lastSubmit.method).toBe('patch');
        expect(lastSubmit.url).toBe('/employees/42/profile');
        // Other form fields still flow through unchanged.
        expect(lastSubmit.data?.basic_salary_centavos).toBe(5_000_000);
        expect(lastSubmit.data?.exempted_contribution_codes).toEqual([
            'BIR_WITHHOLDING',
        ]);
    });
});
