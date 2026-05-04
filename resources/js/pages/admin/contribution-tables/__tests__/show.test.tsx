import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ContributionTablesShow from '@/pages/admin/contribution-tables/show';
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

const TODAY = new Date().toISOString().slice(0, 10);
const FUTURE = '2099-01-01';
const PAST = '2000-01-01';

function makeRow(
    overrides: Partial<StatutoryContributionRow> = {},
): StatutoryContributionRow {
    return {
        id: 42,
        contribution_code: 'SSS',
        label: 'SSS contribution',
        algorithm: 'flat_percentage',
        effective_from: FUTURE,
        effective_to: null,
        rules: {
            rate_bp: 500,
            employee_share_bp: 10000,
            employer_share_bp: 0,
            cap: null,
        },
        notes: null,
        voided_at: null,
        voided_by: null,
        is_editable: true,
        application_order: 100,
        applies_to: 'gross_pay',
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        ...overrides,
    };
}

function renderShow(
    contribution: StatutoryContributionRow,
    {
        history = [],
        can = { update: true, void: true },
    }: {
        history?: StatutoryContributionRow[];
        can?: { update: boolean; void: boolean };
    } = {},
) {
    return render(
        <ContributionTablesShow
            contribution={contribution}
            history={history}
            can={can}
        />,
    );
}

describe('ContributionTablesShow — status badge', () => {
    beforeEach(() => {
        routerPost.mockReset();
    });

    it('renders the Future badge for a future-dated, open-ended row', () => {
        renderShow(makeRow({ effective_from: FUTURE, effective_to: null }));

        expect(screen.getByLabelText('Status: Future')).toBeInTheDocument();
    });

    it('renders the Active badge for a past-dated, open-ended row', () => {
        renderShow(makeRow({ effective_from: PAST, effective_to: null }));

        expect(screen.getByLabelText('Status: Active')).toBeInTheDocument();
    });

    it('renders the Superseded badge for a past-dated, closed row', () => {
        renderShow(makeRow({ effective_from: PAST, effective_to: TODAY }));

        expect(screen.getByLabelText('Status: Superseded')).toBeInTheDocument();
    });

    it('renders the Voided badge for a voided row', () => {
        renderShow(
            makeRow({
                voided_at: '2026-01-15T00:00:00Z',
                voided_by: { id: 1, name: 'Jane Doe' },
            }),
        );

        expect(screen.getByLabelText('Status: Voided')).toBeInTheDocument();
        expect(screen.getByText(/Jane Doe/)).toBeInTheDocument();
    });
});

describe('ContributionTablesShow — history sub-list', () => {
    beforeEach(() => {
        routerPost.mockReset();
    });

    it('renders no other-versions card when history is empty', () => {
        renderShow(makeRow(), { history: [] });

        expect(screen.queryByText('Other versions')).not.toBeInTheDocument();
    });

    it('renders each history row as a clickable link', () => {
        const olderRow = makeRow({
            id: 7,
            effective_from: '2024-01-01',
            effective_to: FUTURE,
        });

        renderShow(makeRow(), { history: [olderRow] });

        expect(screen.getByText('Other versions')).toBeInTheDocument();

        const link = screen.getByLabelText('Open version 7');
        expect(link).toHaveAttribute(
            'href',
            expect.stringContaining('/admin/contribution-tables/7'),
        );
    });
});

describe('ContributionTablesShow — action button gating', () => {
    beforeEach(() => {
        routerPost.mockReset();
    });

    it('hides Edit and Void buttons when can.update and can.void are false', () => {
        renderShow(makeRow(), { can: { update: false, void: false } });

        expect(
            screen.queryByRole('link', { name: /^Edit$/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /^Void$/ }),
        ).not.toBeInTheDocument();
    });

    it('shows Edit and Void buttons when can.update and can.void are true', () => {
        renderShow(makeRow(), { can: { update: true, void: true } });

        expect(
            screen.getByRole('link', { name: /^Edit$/ }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /^Void$/ }),
        ).toBeInTheDocument();
    });
});

describe('ContributionTablesShow — void confirmation flow', () => {
    beforeEach(() => {
        routerPost.mockReset();
    });

    it('opens an AlertDialog and calls router.post on confirm', async () => {
        renderShow(makeRow({ id: 42 }));

        // Click the toolbar Void button — opens the dialog.
        fireEvent.click(screen.getByRole('button', { name: /^Void$/ }));

        // The AlertDialog title is the question.
        const dialog = await screen.findByRole('alertdialog');
        expect(within(dialog).getByText(/^Void SSS\?$/)).toBeInTheDocument();

        // Confirm action.
        const confirm = within(dialog).getByRole('button', {
            name: /Void contribution/,
        });
        fireEvent.click(confirm);

        expect(routerPost).toHaveBeenCalledTimes(1);
        const [url] = routerPost.mock.calls[0] as [string, ...unknown[]];
        expect(url).toContain('/admin/contribution-tables/42/void');
    });
});
