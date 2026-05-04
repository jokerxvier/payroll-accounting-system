import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ContributionTablesEdit from '@/pages/admin/contribution-tables/edit';
import type { StatutoryContributionRow } from '@/types';

const routerPatch = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        patch: (...args: unknown[]) => routerPatch(...args),
    },
    usePage: () => ({ props: { auth: { user: null } } }),
}));

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

const ALGORITHMS = [
    'bracket_table',
    'salary_band',
    'percentage_with_cap',
    'tiered_percentage',
    'flat_percentage',
];

const RECOMMENDED = [
    'BIR_WITHHOLDING',
    'SSS',
    'PHILHEALTH',
    'PAGIBIG',
    'CITY_TAX',
];

function makeRow(
    overrides: Partial<StatutoryContributionRow> = {},
): StatutoryContributionRow {
    return {
        id: 99,
        contribution_code: 'CITY_TAX',
        label: 'Quezon City local tax',
        algorithm: 'flat_percentage',
        effective_from: '2099-06-01',
        effective_to: null,
        rules: {
            rate_bp: 150,
            employee_share_bp: 10000,
            employer_share_bp: 0,
            cap: null,
        },
        notes: 'Per QC Ordinance 2099-001.',
        voided_at: null,
        voided_by: null,
        is_editable: true,
        application_order: 50,
        applies_to: 'gross_pay',
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        ...overrides,
    };
}

function renderEdit(row: StatutoryContributionRow = makeRow()) {
    return render(
        <ContributionTablesEdit
            contribution={row}
            recommendedCodes={RECOMMENDED}
            algorithmOptions={ALGORITHMS}
        />,
    );
}

describe('ContributionTablesEdit', () => {
    beforeEach(() => {
        routerPatch.mockReset();
    });

    it('prefills the form with the row values', () => {
        renderEdit();

        const codeInput = screen.getByLabelText(
            /^Contribution code$/i,
        ) as HTMLInputElement;
        expect(codeInput.value).toBe('CITY_TAX');

        const labelInput = screen.getByLabelText(
            /^Label$/i,
        ) as HTMLInputElement;
        expect(labelInput.value).toBe('Quezon City local tax');

        const orderInput = screen.getByLabelText(
            /^Application order$/i,
        ) as HTMLInputElement;
        expect(orderInput.value).toBe('50');

        const notesInput = screen.getByLabelText(
            /^Notes$/i,
        ) as HTMLTextAreaElement;
        expect(notesInput.value).toBe('Per QC Ordinance 2099-001.');
    });

    it('renders the contribution_code field as disabled', () => {
        renderEdit();

        const codeInput = screen.getByLabelText(
            /^Contribution code$/i,
        ) as HTMLInputElement;

        expect(codeInput).toBeDisabled();
        expect(codeInput).toHaveAttribute('readonly');
    });

    it('renders a banner mentioning the effective_from date', () => {
        renderEdit(makeRow({ effective_from: '2099-06-15' }));

        // The banner uses date-fns 'PP' formatter → "Jun 15, 2099".
        const banner = screen.getByRole('status');
        expect(banner).toHaveTextContent(/Jun 15, 2099/);
        expect(banner).toHaveTextContent(/become read-only/);
    });

    it('submits via PATCH with the form payload', () => {
        renderEdit(makeRow({ id: 99 }));

        const labelInput = screen.getByLabelText(
            /^Label$/i,
        ) as HTMLInputElement;
        fireEvent.change(labelInput, {
            target: { value: 'Updated label' },
        });

        const form = labelInput.closest('form');
        expect(form).not.toBeNull();
        fireEvent.submit(form as HTMLFormElement);

        expect(routerPatch).toHaveBeenCalledTimes(1);
        const [url, payload] = routerPatch.mock.calls[0] as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toContain('/admin/contribution-tables/99');
        expect(payload.label).toBe('Updated label');
        expect(payload.contribution_code).toBe('CITY_TAX');
    });
});
