import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BalanceSheetReport from '@/pages/admin/accounting/reports/balance-sheet';
import type {
    BalanceSheetColumn,
    BalanceSheetPageProps,
    StatementLine,
} from '@/types/financial-statement';

const formGet = vi.fn();
let transformed: Record<string, unknown> = {};

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

function column(
    overrides: Partial<BalanceSheetColumn> = {},
): BalanceSheetColumn {
    return {
        label: 'As at 31 Dec 2026',
        as_at: '2026-12-31',
        fiscal_year_start: '2026-01-01',
        assets_centavos: 800_000,
        liabilities_centavos: 200_000,
        equity_centavos: 600_000,
        liabilities_and_equity_centavos: 800_000,
        is_balanced: true,
        variance_centavos: 0,
        ...overrides,
    };
}

function line(overrides: Partial<StatementLine> = {}): StatementLine {
    return {
        kind: 'account',
        key: 'account:1',
        section: 'current_assets',
        name: 'Cash on Hand',
        amounts_centavos: [650_000],
        account_id: 1,
        code: '1100',
        is_active: true,
        is_unclassified: false,
        variance_centavos: null,
        variance_bp: null,
        variance_favourable: null,
        drill: {
            report: 'general-ledger',
            from: '2026-01-01',
            to: '2026-12-31',
        },
        ...overrides,
    };
}

const earlier = column({
    label: 'As at 31 Dec 2025',
    as_at: '2025-12-31',
    fiscal_year_start: '2025-01-01',
    assets_centavos: 300_000,
    liabilities_centavos: 0,
    equity_centavos: 300_000,
    liabilities_and_equity_centavos: 300_000,
});

/** The address a drill-down comes back to, as the links encode it. */
const HERE = encodeURIComponent(
    '/admin/reports/balance-sheet?as_at=2026-12-31&compare=none&include_zero=0',
);

function renderPage(props: Partial<BalanceSheetPageProps> = {}) {
    return render(
        <BalanceSheetReport
            filters={{
                as_at: '2026-12-31',
                compare: 'none',
                compare_as_at: null,
                include_zero: false,
            }}
            columns={[column()]}
            hasVariance={false}
            notices={[]}
            lines={[line()]}
            {...props}
        />,
    );
}

describe('balance sheet', () => {
    beforeEach(() => {
        formGet.mockClear();
        transformed = {};
    });

    it('states the verdict before the reader has to add anything up', () => {
        renderPage();

        expect(screen.getByText('The statement balances.')).toBeInTheDocument();
    });

    it('names the direction and size of a discrepancy', () => {
        renderPage({
            columns: [
                column({ is_balanced: false, variance_centavos: -12_345 }),
            ],
        });

        expect(
            screen.getByText('The statement does not balance.'),
        ).toBeInTheDocument();
        expect(screen.getByText(/fall short of/)).toBeInTheDocument();
        expect(screen.getByText('₱123.45')).toBeInTheDocument();
    });

    it('writes its dates the way a reader would', () => {
        renderPage();

        // Once as the card's title and once as the column heading.
        expect(screen.getAllByText('As at 31 Dec 2026')).toHaveLength(2);
        expect(
            screen.getByText(/Current year from 1 Jan 2026/),
        ).toBeInTheDocument();
    });

    it('links an account to its ledger, with a way back', () => {
        renderPage();

        expect(
            screen.getByRole('link', { name: /Cash on Hand/ }),
        ).toHaveAttribute(
            'href',
            `/admin/reports/general-ledger?account_id=1&from=2026-01-01&to=2026-12-31&return_to=${HERE}`,
        );
    });

    it('links current-year earnings to the income statement', () => {
        renderPage({
            lines: [
                line({
                    kind: 'computed',
                    key: 'current_year_earnings',
                    section: 'equity',
                    name: 'Current-year earnings',
                    account_id: null,
                    code: null,
                    amounts_centavos: [300_000],
                    drill: {
                        report: 'income-statement',
                        from: '2026-01-01',
                        to: '2026-12-31',
                    },
                }),
            ],
        });

        expect(
            screen.getByRole('link', { name: 'Current-year earnings' }),
        ).toHaveAttribute(
            'href',
            `/admin/reports/income-statement?preset=custom&from=2026-01-01&to=2026-12-31&return_to=${HERE}`,
        );
    });

    it('does not link a figure that no single page can show', () => {
        // Retained earnings with unclosed profit folded in: the ledger would
        // show a different number, so the parts carry the links instead.
        renderPage({
            lines: [
                line({
                    key: 'retained_earnings',
                    section: 'equity',
                    name: 'Retained Earnings',
                    code: '3200',
                    amounts_centavos: [270_000],
                    drill: null,
                }),
            ],
        });

        expect(
            screen.queryByRole('link', { name: /Retained Earnings/ }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Retained Earnings')).toBeInTheDocument();
    });

    it('prints a contra account in brackets rather than as a positive', () => {
        renderPage({
            lines: [
                line({
                    key: 'account:2',
                    name: 'Accumulated Depreciation',
                    code: '1520',
                    amounts_centavos: [-50_000],
                }),
            ],
        });

        const row = screen.getByText('Accumulated Depreciation').closest('tr');

        expect(
            within(row as HTMLElement).getByText('(500.00)'),
        ).toBeInTheDocument();
    });

    it('prints a nil total as a figure, not an absence', () => {
        renderPage({
            lines: [
                line({
                    kind: 'total',
                    key: 'total_assets',
                    section: 'total_assets',
                    name: 'Total assets',
                    amounts_centavos: [0],
                    account_id: null,
                    code: null,
                    drill: null,
                }),
            ],
        });

        const total = screen.getByText('Total assets').closest('tr');

        expect(
            within(total as HTMLElement).getByText('0.00'),
        ).toBeInTheDocument();
    });

    it('marks an inactive account and one with no subtype', () => {
        renderPage({
            lines: [line({ is_active: false, is_unclassified: true })],
        });

        expect(screen.getByText('Inactive')).toBeInTheDocument();
        expect(screen.getByText('No subtype')).toBeInTheDocument();
    });

    describe('compared with an earlier date', () => {
        const compared = {
            filters: {
                as_at: '2026-12-31',
                compare: 'previous_year_end' as const,
                compare_as_at: '2025-12-31',
                include_zero: false,
            },
            columns: [column(), earlier],
            hasVariance: true,
            lines: [
                line({
                    amounts_centavos: [650_000, 300_000],
                    variance_centavos: 350_000,
                    variance_bp: 11667,
                }),
            ],
        };

        it('gives each date its own verdict', () => {
            renderPage({
                ...compared,
                columns: [
                    column({ is_balanced: false, variance_centavos: 12_345 }),
                    earlier,
                ],
            });

            expect(
                screen.getByText(
                    'The statement does not balance as at 31 Dec 2026.',
                ),
            ).toBeInTheDocument();
            expect(
                screen.getByText('The statement balances as at 31 Dec 2025.'),
            ).toBeInTheDocument();
        });

        it('states the change without colouring it good or bad', () => {
            renderPage(compared);

            const amount = screen.getByText('+3,500.00');
            const percent = screen.getByText('+116.67%');

            expect(amount).not.toHaveClass('text-success');
            expect(amount).not.toHaveClass('text-destructive');
            expect(percent).not.toHaveClass('text-success');
        });

        it('links each figure to the ledger up to its own date', () => {
            renderPage(compared);

            expect(
                screen
                    .getByRole('link', {
                        name: 'Cash on Hand, As at 31 Dec 2025: open ledger',
                    })
                    .getAttribute('href'),
            ).toContain(
                '/admin/reports/general-ledger?account_id=1&from=2025-01-01&to=2025-12-31',
            );
        });

        it('exports the comparison on screen', () => {
            renderPage(compared);

            expect(screen.getByTestId('export-url')).toHaveTextContent(
                '/admin/reports/balance-sheet/export?as_at=2026-12-31&compare=previous_year_end&include_zero=0',
            );
        });
    });

    it('sends the chosen date only when a date is being chosen', () => {
        renderPage({
            filters: {
                as_at: '2026-12-31',
                compare: 'custom',
                compare_as_at: '2026-06-30',
                include_zero: false,
            },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

        expect(formGet).toHaveBeenCalledWith('/admin/reports/balance-sheet', {
            as_at: '2026-12-31',
            compare: 'custom',
            compare_as_at: '2026-06-30',
            include_zero: 0,
        });
    });

    it('says what had to be changed about the comparison', () => {
        renderPage({ notices: ['Choose a date to compare against.'] });

        expect(screen.getByRole('status')).toHaveTextContent(
            'Choose a date to compare against.',
        );
    });
});
