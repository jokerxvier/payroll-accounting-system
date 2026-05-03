import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const usePagePropsRef: { current: unknown } = { current: null };
const requestRecomputeMock = vi.fn();
const cancelMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        post: vi.fn(),
    },
    usePage: () => ({ props: usePagePropsRef.current }),
}));

vi.mock('@/hooks/use-payroll-preview', () => ({
    usePayrollPreview: () => ({
        isComputing: false,
        requestRecompute: requestRecomputeMock,
        cancel: cancelMock,
    }),
}));

vi.mock('@/routes/payroll/preview', () => ({
    show: () => ({ url: '/payroll/preview', method: 'get' }),
    compute: () => ({ url: '/payroll/preview/compute', method: 'post' }),
}));

vi.mock('@/routes/employees', () => ({
    show: ({ staffId }: { staffId: number | string }) => ({
        url: `/employees/${staffId}`,
        method: 'get',
    }),
}));

import PayrollPreview from '@/pages/payroll/preview';
import type {
    AuditLineDTO,
    PayrollComputationResultDTO,
    PreviewPageProps,
} from '@/types';

function makeMoney(centavos: number) {
    return { centavos, formatted: (centavos / 100).toFixed(2) };
}

function makeAuditLine(overrides: Partial<AuditLineDTO> = {}): AuditLineDTO {
    return {
        code: 'BASIC_PAY',
        label: 'Basic pay',
        amount: makeMoney(2_500_000),
        bucket: 'earning',
        meta: null,
        ...overrides,
    };
}

function makeResult(
    overrides: Partial<PayrollComputationResultDTO> = {},
): PayrollComputationResultDTO {
    return {
        period: {
            frequency: 'monthly',
            start: '2026-05-01',
            end: '2026-05-31',
            daysInPeriod: 31,
        },
        basicPay: makeMoney(2_500_000),
        grossPay: makeMoney(2_500_000),
        sssEmployee: makeMoney(112_500),
        sssEmployer: makeMoney(225_000),
        sssEmployerEc: makeMoney(3_000),
        philhealthEmployee: makeMoney(62_500),
        philhealthEmployer: makeMoney(62_500),
        pagibigEmployee: makeMoney(20_000),
        pagibigEmployer: makeMoney(20_000),
        birWithholdingTax: makeMoney(125_000),
        totalEmployeeDeductions: makeMoney(320_000),
        totalEmployerContributions: makeMoney(310_500),
        taxableIncome: makeMoney(2_305_000),
        netPay: makeMoney(2_180_000),
        allowancesTaxable: makeMoney(0),
        allowancesNonTaxable: makeMoney(0),
        customDeductionsEmployee: makeMoney(0),
        customDeductionsEmployer: makeMoney(0),
        loanDeductions: makeMoney(0),
        adjustmentTaxableAdditions: makeMoney(0),
        adjustmentNonTaxableAdditions: makeMoney(0),
        adjustmentDeductions: makeMoney(0),
        unpaidDaysReduction: makeMoney(0),
        unpaidDaysCount: 0,
        auditLines: [
            makeAuditLine(),
            makeAuditLine({
                code: 'SSS_EMPLOYEE',
                label: 'SSS (employee share)',
                amount: makeMoney(112_500),
                bucket: 'employee_deduction',
            }),
            makeAuditLine({
                code: 'SSS_EMPLOYER',
                label: 'SSS (employer share)',
                amount: makeMoney(225_000),
                bucket: 'employer_contribution',
            }),
        ],
        ...overrides,
    };
}

function makeProps(
    overrides: Partial<PreviewPageProps> = {},
): PreviewPageProps {
    return {
        employees: [
            {
                lms_staff_id: 1,
                full_name: 'Maria Reyes',
                employment_type: 'regular',
                has_profile: true,
            },
        ],
        frequencyOptions: {
            monthly: 'Monthly',
            semi_monthly_first: 'Semi-monthly (1st half)',
            semi_monthly_second: 'Semi-monthly (2nd half)',
        },
        defaultPeriod: { frequency: 'monthly', year: 2026, month: 5 },
        selection: null,
        noProfile: null,
        currentPeriod: null,
        priorPeriod: null,
        computedAt: null,
        ...overrides,
    };
}

describe('payroll preview page', () => {
    beforeEach(() => {
        requestRecomputeMock.mockReset();
        cancelMock.mockReset();
    });

    it('renders the empty state when no employee is selected', () => {
        usePagePropsRef.current = makeProps();

        render(<PayrollPreview />);

        expect(
            screen.getByText('Pick an employee and period to preview'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/selections re-compute automatically/i),
        ).toBeInTheDocument();
    });

    it('renders both period columns when current and prior results are populated', () => {
        usePagePropsRef.current = makeProps({
            selection: {
                lms_staff_id: 1,
                frequency: 'monthly',
                year: 2026,
                month: 5,
            },
            noProfile: false,
            currentPeriod: makeResult(),
            priorPeriod: makeResult({
                period: {
                    frequency: 'monthly',
                    start: '2026-04-01',
                    end: '2026-04-30',
                    daysInPeriod: 30,
                },
                netPay: makeMoney(2_120_000),
            }),
            computedAt: '2026-05-03T12:00:00+08:00',
        });

        render(<PayrollPreview />);

        expect(screen.getByText('Current period')).toBeInTheDocument();
        expect(screen.getByText('Prior period')).toBeInTheDocument();

        // Net-pay rows in both columns.
        const netPayLabels = screen.getAllByText('Net pay');
        expect(netPayLabels.length).toBeGreaterThanOrEqual(2);

        // Bucket headings appear once per column.
        expect(screen.getAllByText('Earnings').length).toBe(2);
        expect(screen.getAllByText('Employee deductions').length).toBe(2);
        expect(screen.getAllByText('Employer contributions').length).toBe(2);
    });

    it('renders the noProfile notice with a link to the employee record', () => {
        usePagePropsRef.current = makeProps({
            selection: {
                lms_staff_id: 1,
                frequency: 'monthly',
                year: 2026,
                month: 5,
            },
            noProfile: true,
        });

        render(<PayrollPreview />);

        expect(screen.getByText('No payroll profile')).toBeInTheDocument();
        // The notice copy includes the staff name; scope the assertion via
        // the regex anchored to the notice prose to disambiguate from the
        // typeahead button label.
        expect(screen.getByText(/has no payroll profile/i)).toBeInTheDocument();
        const link = screen.getByRole('link', {
            name: /open employee record/i,
        });
        expect(link).toHaveAttribute('href', '/employees/1');
    });

    it('shows the unpaid-leave stub badge when no unpaid days were applied', () => {
        usePagePropsRef.current = makeProps({
            selection: {
                lms_staff_id: 1,
                frequency: 'monthly',
                year: 2026,
                month: 5,
            },
            noProfile: false,
            currentPeriod: makeResult({
                unpaidDaysCount: 0,
                unpaidDaysReduction: makeMoney(0),
            }),
            priorPeriod: null,
            computedAt: '2026-05-03T12:00:00+08:00',
        });

        render(<PayrollPreview />);

        expect(
            screen.getByText(/stub — pending LMS leave bridge/i),
        ).toBeInTheDocument();
    });

    it('shows the de-minimis caps badge when allowance lines are present', () => {
        usePagePropsRef.current = makeProps({
            selection: {
                lms_staff_id: 1,
                frequency: 'monthly',
                year: 2026,
                month: 5,
            },
            noProfile: false,
            currentPeriod: makeResult({
                allowancesTaxable: makeMoney(50_000),
                auditLines: [
                    makeAuditLine(),
                    makeAuditLine({
                        code: 'allowance_taxable',
                        label: 'Transportation allowance',
                        amount: makeMoney(50_000),
                        bucket: 'earning',
                    }),
                ],
            }),
            priorPeriod: null,
            computedAt: '2026-05-03T12:00:00+08:00',
        });

        render(<PayrollPreview />);

        expect(
            screen.getByText(/de-minimis caps pending client confirmation/i),
        ).toBeInTheDocument();
    });
});
