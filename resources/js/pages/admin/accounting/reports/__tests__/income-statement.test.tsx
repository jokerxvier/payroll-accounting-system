import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import IncomeStatementReport from '@/pages/admin/accounting/reports/income-statement';
import type {
    IncomeStatementFilters,
    StatementColumn,
    StatementLine,
} from '@/types/financial-statement';

const formGet = vi.fn();
let transformed: Record<string, unknown> = {};

// Stateful, because the page's behaviour lives between a click and the visit
// it causes: choosing a preset has to carry the comparison along with it.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    useForm: (initial: Record<string, unknown>) => {
        const [data, setAll] = useState(initial);

        return {
            data,
            setData: (key: string, value: unknown) =>
                setAll((current) => ({ ...current, [key]: value })),
            transform: (
                callback: (data: unknown) => Record<string, unknown>,
            ) => {
                transformed = callback(data);
            },
            get: (url: string) => formGet(url, transformed),
            processing: false,
        };
    },
}));

// The menu keeps its links inside a closed dropdown. What matters here is the
// URL the page hands it, so that is what this stands in to show.
vi.mock('@/components/admin/report-export-menu', () => ({
    ReportExportMenu: ({ baseUrl }: { baseUrl: string }) => (
        <span data-testid="export-url">{baseUrl}</span>
    ),
}));

function column(overrides: Partial<StatementColumn> = {}): StatementColumn {
    return {
        key: 'primary',
        label: 'Aug 2026',
        from: '2026-08-01',
        to: '2026-08-31',
        role: 'primary',
        ...overrides,
    };
}

function line(overrides: Partial<StatementLine> = {}): StatementLine {
    return {
        kind: 'account',
        key: 'account:7',
        section: 'operating_expenses',
        name: 'Salaries and Wages',
        amounts_centavos: [450_000],
        account_id: 7,
        code: '5100',
        is_active: true,
        is_unclassified: false,
        variance_centavos: null,
        variance_bp: null,
        variance_favourable: null,
        drill: null,
        ...overrides,
    };
}

function renderPage({
    filters = {},
    columns = [column()],
    lines = [line()],
    hasVariance = false,
    notices = [],
    backHref = null,
}: {
    backHref?: string | null;
    filters?: Partial<IncomeStatementFilters>;
    columns?: StatementColumn[];
    lines?: StatementLine[];
    hasVariance?: boolean;
    notices?: string[];
} = {}) {
    return render(
        <TooltipProvider>
            <IncomeStatementReport
                filters={{
                    preset: 'custom',
                    from: '2026-08-01',
                    to: '2026-08-31',
                    compare: 'none',
                    compare_from: null,
                    compare_to: null,
                    include_zero: false,
                    ...filters,
                }}
                columns={columns}
                hasVariance={hasVariance}
                notices={notices}
                lines={lines}
                backHref={backHref}
            />
        </TooltipProvider>,
    );
}

/** The address a drill-down comes back to, as the links encode it. */
const HERE = encodeURIComponent(
    '/admin/reports/income-statement?preset=custom&from=2026-08-01&to=2026-08-31&compare=none&include_zero=0',
);

const comparison = column({
    key: 'comparison',
    label: 'Jul 2026',
    from: '2026-07-01',
    to: '2026-07-31',
    role: 'comparison',
});

describe('income statement', () => {
    beforeEach(() => {
        formGet.mockClear();
        transformed = {};
    });

    it('links a figure to the ledger for its own dates', () => {
        renderPage();

        expect(
            screen.getByRole('link', { name: /Salaries and Wages/ }),
        ).toHaveAttribute(
            'href',
            `/admin/reports/general-ledger?account_id=7&from=2026-08-01&to=2026-08-31&return_to=${HERE}`,
        );
    });

    it("links each column of a comparison to that column's dates", () => {
        renderPage({
            columns: [column(), comparison],
            hasVariance: true,
            lines: [
                line({
                    amounts_centavos: [450_000, 300_000],
                    variance_centavos: 150_000,
                    variance_bp: 5000,
                    variance_favourable: false,
                }),
            ],
        });

        expect(
            screen.getByRole('link', {
                name: 'Salaries and Wages, Jul 2026: open ledger',
            }),
        ).toHaveAttribute(
            'href',
            expect.stringContaining(
                '/admin/reports/general-ledger?account_id=7&from=2026-07-01&to=2026-07-31&return_to=',
            ),
        );
    });

    it('shows a rise in an expense as an increase that is bad news', () => {
        renderPage({
            columns: [column(), comparison],
            hasVariance: true,
            lines: [
                line({
                    amounts_centavos: [450_000, 300_000],
                    variance_centavos: 150_000,
                    variance_bp: 5000,
                    variance_favourable: false,
                }),
            ],
        });

        const amount = screen.getByText('+1,500.00');
        const percent = screen.getByText('+50.00%');

        expect(amount).toHaveClass('text-destructive');
        expect(percent).toHaveClass('text-destructive');
    });

    it('has no percentage for a line that was nil in the comparison', () => {
        renderPage({
            columns: [column(), comparison],
            hasVariance: true,
            lines: [
                line({
                    amounts_centavos: [50_000, 0],
                    variance_centavos: 50_000,
                    variance_bp: null,
                    variance_favourable: false,
                }),
            ],
        });

        const row = screen.getByText('Salaries and Wages').closest('tr');

        expect(
            within(row as HTMLElement).getByText('+500.00'),
        ).toBeInTheDocument();
        expect(
            within(row as HTMLElement).queryByText(/%/),
        ).not.toBeInTheDocument();
    });

    it('leaves the variance columns out of a month-by-month statement', () => {
        renderPage({
            filters: { compare: 'monthly' },
            columns: [
                column({ key: 'm202607', label: 'Jul 2026', role: 'month' }),
                column({ key: 'm202608', label: 'Aug 2026', role: 'month' }),
                column({ key: 'total', label: 'Total', role: 'total' }),
            ],
            lines: [line({ amounts_centavos: [300_000, 450_000, 750_000] })],
        });

        expect(
            screen.getByRole('columnheader', { name: 'Jul 2026' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('columnheader', { name: 'Total' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('columnheader', { name: 'Variance' }),
        ).not.toBeInTheDocument();
    });

    it('prints a loss in brackets', () => {
        renderPage({
            lines: [
                line({
                    kind: 'total',
                    key: 'net_income',
                    section: 'net_income',
                    name: 'Net income',
                    account_id: null,
                    code: null,
                    amounts_centavos: [-50_000],
                }),
            ],
        });

        expect(screen.getByText('(500.00)')).toBeInTheDocument();
    });

    it('says what had to be changed about the comparison', () => {
        renderPage({
            notices: [
                'Month-by-month columns cover at most 12 months. Choose a shorter range to see them.',
            ],
        });

        expect(screen.getByRole('status')).toHaveTextContent(
            'at most 12 months',
        );
    });

    it('says so when nothing moved, and what to do about it', () => {
        renderPage({ lines: [] });

        expect(
            screen.getByText(/No income or expense account moved/),
        ).toBeInTheDocument();
    });

    it('carries the comparison along when a preset is chosen', () => {
        renderPage({ filters: { compare: 'previous_year' } });

        fireEvent.click(screen.getByRole('button', { name: 'This quarter' }));

        expect(formGet).toHaveBeenCalledWith(
            '/admin/reports/income-statement',
            { preset: 'quarter', compare: 'previous_year', include_zero: 0 },
        );
    });

    it('sends the custom comparison dates only when they are in use', () => {
        renderPage({
            filters: {
                compare: 'custom',
                compare_from: '2026-03-01',
                compare_to: '2026-03-31',
            },
        });

        // Two Apply buttons on a custom range: the range's, then the
        // comparison's. Either sends everything.
        fireEvent.click(screen.getAllByRole('button', { name: 'Apply' })[1]);

        expect(formGet).toHaveBeenLastCalledWith(
            '/admin/reports/income-statement',
            {
                preset: 'custom',
                from: '2026-08-01',
                to: '2026-08-31',
                compare: 'custom',
                compare_from: '2026-03-01',
                compare_to: '2026-03-31',
                include_zero: 0,
            },
        );
    });

    it('offers a way back, and keeps it when a filter changes', () => {
        const backHref = '/admin/reports/balance-sheet?as_at=2026-08-31';

        renderPage({ backHref });

        expect(screen.getByRole('link', { name: /Back/ })).toHaveAttribute(
            'href',
            backHref,
        );

        fireEvent.click(screen.getByRole('button', { name: 'This quarter' }));

        expect(formGet).toHaveBeenCalledWith(
            '/admin/reports/income-statement',
            expect.objectContaining({ return_to: backHref }),
        );
    });

    it('has no back button when it was opened directly', () => {
        renderPage();

        expect(
            screen.queryByRole('link', { name: /Back/ }),
        ).not.toBeInTheDocument();
    });

    it('writes its dates the way a reader would', () => {
        renderPage();

        expect(
            screen.getByText('1 Aug 2026 to 31 Aug 2026'),
        ).toBeInTheDocument();
    });

    it('exports the statement on screen, not the one half chosen', () => {
        renderPage({ filters: { compare: 'previous_year' } });

        // Changed in the form but not applied: the page still shows the
        // comparison with last year, so that is what the file must hold.
        fireEvent.click(screen.getByRole('button', { name: 'This quarter' }));

        expect(screen.getByTestId('export-url')).toHaveTextContent(
            '/admin/reports/income-statement/export?preset=custom&from=2026-08-01&to=2026-08-31&compare=previous_year&include_zero=0',
        );
    });
});
