import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ContributionTablesIndex from '@/pages/admin/contribution-tables/index';
import type { StatutoryContributionRow } from '@/types';

const routerPost = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        post: (...args: unknown[]) => routerPost(...args),
    },
    usePage: () => ({ props: { auth: { user: null } } }),
}));

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

const FUTURE = '2099-01-01';
const PAST = '2000-01-01';

const RECOMMENDED = [
    'BIR_WITHHOLDING',
    'SSS',
    'PHILHEALTH',
    'PAGIBIG',
    'CITY_TAX',
];
const ALGORITHMS = [
    'bracket_table',
    'salary_band',
    'percentage_with_cap',
    'tiered_percentage',
    'flat_percentage',
];

function makeRow(
    overrides: Partial<StatutoryContributionRow> = {},
): StatutoryContributionRow {
    return {
        id: 1,
        contribution_code: 'SSS',
        label: 'SSS contribution',
        algorithm: 'salary_band',
        effective_from: FUTURE,
        effective_to: null,
        rules: {},
        notes: null,
        voided_at: null,
        voided_by: null,
        is_editable: true,
        application_order: 10,
        applies_to: 'gross_pay',
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        ...overrides,
    };
}

interface RenderOptions {
    rows?: StatutoryContributionRow[];
    can?: { modify: boolean };
}

function renderIndex({
    rows = [makeRow()],
    can = { modify: true },
}: RenderOptions = {}) {
    return render(
        <ContributionTablesIndex
            grouped={{ SSS: rows }}
            recommendedCodes={RECOMMENDED}
            algorithmOptions={ALGORITHMS}
            can={can}
        />,
    );
}

describe('ContributionTablesIndex — chevron action', () => {
    beforeEach(() => {
        routerPost.mockReset();
    });

    it('renders a View chevron link for every row regardless of editability', () => {
        renderIndex({
            rows: [
                makeRow({ id: 1, is_editable: true }),
                makeRow({
                    id: 2,
                    is_editable: false,
                    effective_from: PAST,
                }),
                makeRow({
                    id: 3,
                    is_editable: false,
                    voided_at: '2026-01-15T00:00:00Z',
                }),
            ],
        });

        expect(screen.getByLabelText('View row 1')).toHaveAttribute(
            'href',
            expect.stringContaining('/admin/contribution-tables/1'),
        );
        expect(screen.getByLabelText('View row 2')).toBeInTheDocument();
        expect(screen.getByLabelText('View row 3')).toBeInTheDocument();
    });
});

describe('ContributionTablesIndex — inline Edit and Void buttons', () => {
    beforeEach(() => {
        routerPost.mockReset();
    });

    it('shows Edit and Void icon buttons when row.is_editable and can.modify are both true', () => {
        renderIndex({
            rows: [makeRow({ id: 42, is_editable: true })],
            can: { modify: true },
        });

        expect(screen.getByLabelText('Edit row 42')).toHaveAttribute(
            'href',
            expect.stringContaining('/admin/contribution-tables/42/edit'),
        );
        expect(screen.getByLabelText('Void row 42')).toBeInTheDocument();
    });

    it('hides Edit and Void buttons when row.is_editable is false', () => {
        renderIndex({
            rows: [
                makeRow({
                    id: 7,
                    is_editable: false,
                    effective_from: PAST,
                }),
            ],
            can: { modify: true },
        });

        expect(screen.queryByLabelText('Edit row 7')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Void row 7')).not.toBeInTheDocument();
        // The chevron is still there.
        expect(screen.getByLabelText('View row 7')).toBeInTheDocument();
    });

    it('hides Edit and Void buttons when can.modify is false even on editable rows', () => {
        renderIndex({
            rows: [makeRow({ id: 9, is_editable: true })],
            can: { modify: false },
        });

        expect(screen.queryByLabelText('Edit row 9')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Void row 9')).not.toBeInTheDocument();
        expect(screen.getByLabelText('View row 9')).toBeInTheDocument();
    });
});

describe('ContributionTablesIndex — void confirmation flow', () => {
    beforeEach(() => {
        routerPost.mockReset();
    });

    it('opens an AlertDialog when the Void icon button is clicked', async () => {
        renderIndex({
            rows: [
                makeRow({
                    id: 55,
                    contribution_code: 'SSS',
                    is_editable: true,
                }),
            ],
        });

        fireEvent.click(screen.getByLabelText('Void row 55'));

        const dialog = await screen.findByRole('alertdialog');
        expect(within(dialog).getByText(/^Void SSS\?$/)).toBeInTheDocument();
        expect(
            within(dialog).getByText(/Voiding removes this contribution/),
        ).toBeInTheDocument();
    });

    it('calls router.post against the void URL on confirm', async () => {
        renderIndex({
            rows: [makeRow({ id: 55, is_editable: true })],
        });

        fireEvent.click(screen.getByLabelText('Void row 55'));

        const dialog = await screen.findByRole('alertdialog');
        const confirm = within(dialog).getByRole('button', {
            name: /Void contribution/,
        });
        fireEvent.click(confirm);

        expect(routerPost).toHaveBeenCalledTimes(1);
        const [url] = routerPost.mock.calls[0] as [string, ...unknown[]];
        expect(url).toContain('/admin/contribution-tables/55/void');
    });
});
