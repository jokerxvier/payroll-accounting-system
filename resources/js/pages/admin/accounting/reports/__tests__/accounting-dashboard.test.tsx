import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AccountingDashboard from '@/pages/admin/accounting/reports/accounting-dashboard';
import type { AccountingDashboardPageProps } from '@/types';

const formGet = vi.fn();

/*
 * A STATEFUL useForm shim, not a stub.
 *
 * A stub that echoed the initial data back could not see the bug this page
 * shipped with: clicking Custom sets form state and deliberately does NOT
 * reload, so with a stateless mock the pickers looked correct in tests while
 * being permanently disabled in the browser. The filter's whole behaviour
 * lives between a click and a round trip, so the mock has to hold state
 * across renders.
 */
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        usePage: () => ({ props: { auth: { user: null } } }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setData] = useState<T>(initial);

            return {
                data,
                processing: false,
                setData: (key: keyof T | Partial<T>, value?: T[keyof T]) => {
                    if (typeof key === 'string') {
                        setData((prev) => ({ ...prev, [key]: value }));
                    } else {
                        setData((prev) => ({
                            ...prev,
                            ...(key as Partial<T>),
                        }));
                    }
                },
                transform: vi.fn(),
                get: (...args: unknown[]) => formGet(...args),
            };
        },
    };
});

/*
 * The accounting dashboard.
 *
 * The figures themselves are proved server-side — three Pest files pin them,
 * and one reconciles the dashboard against the trial balance. What is left for
 * the page is that it labels the two kinds of figure honestly, offers the
 * ranges, and says something useful when a range is empty.
 */

function makeProps(
    overrides: Partial<AccountingDashboardPageProps> = {},
): AccountingDashboardPageProps {
    return {
        filters: { preset: 'year', from: '2026-07-01', to: '2027-03-31' },
        summary: {
            from: '2026-07-01',
            to: '2027-03-31',
            cash_centavos: 71_370_000,
            receivables_centavos: 32_610_112,
            payables_centavos: 9_670_000,
            income_centavos: 40_600_100,
            expenses_centavos: 85_877_000,
            net_income_centavos: -45_276_900,
            revenue_by_account: [
                {
                    account_id: 1,
                    code: '4100',
                    name: 'Tuition Fee Income',
                    centavos: 37_350_100,
                },
                {
                    account_id: 2,
                    code: '4900',
                    name: 'Other Income',
                    centavos: 3_250_000,
                },
            ],
            ...(overrides.summary ?? {}),
        },
        monthlySeries: [
            {
                month: '2026-07',
                label: 'Jul 2026',
                income_centavos: 0,
                expenses_centavos: 62_007_000,
            },
            {
                month: '2026-08',
                label: 'Aug 2026',
                income_centavos: 40_050_100,
                expenses_centavos: 23_870_000,
            },
        ],
        ...overrides,
    };
}

function renderDashboard(
    overrides: Partial<AccountingDashboardPageProps> = {},
): void {
    formGet.mockClear();
    render(<AccountingDashboard {...makeProps(overrides)} />);
}

describe('the tiles', () => {
    it('shows all six figures', () => {
        renderDashboard();

        for (const label of [
            'Cash balance',
            'Receivables',
            'Payables',
            'Income',
            'Expenses',
            'Net income',
        ]) {
            expect(screen.getByText(label)).toBeInTheDocument();
        }
    });

    it('renders pesos, not raw centavos', () => {
        renderDashboard();

        expect(screen.getByText('₱713,700.00')).toBeInTheDocument();
    });

    /*
     * The two kinds of figure sit in one row and answer different questions.
     * Without the hints a reader has no way to tell that Income is what moved
     * between the dates while Cash is what is held at the end of them.
     */
    it('says which figures are balances and which are movements', () => {
        renderDashboard();

        expect(
            screen.getByText('As at the end of the range'),
        ).toBeInTheDocument();
        expect(screen.getByText('Earned in the range')).toBeInTheDocument();
    });

    it('shows a loss as negative rather than as a bare number', () => {
        renderDashboard();

        // U+2212, the real minus, not a hyphen.
        expect(screen.getByText(/−₱452,769\.00/)).toBeInTheDocument();
    });
});

describe('the range filter', () => {
    it('offers the four ranges', () => {
        renderDashboard();

        for (const label of [
            'This month',
            'This quarter',
            'This year',
            'Custom',
        ]) {
            expect(
                screen.getByRole('button', { name: label }),
            ).toBeInTheDocument();
        }
    });

    it('reloads as soon as a preset is chosen', () => {
        // A preset needs no Apply — there is nothing left to decide.
        renderDashboard();

        fireEvent.click(screen.getByRole('button', { name: 'This month' }));

        expect(formGet).toHaveBeenCalledTimes(1);
    });

    it('waits for Apply on a custom range', () => {
        // Reloading on the first of two dates would fetch a range nobody asked
        // for, and the second pick would fight the response.
        renderDashboard({
            filters: { preset: 'custom', from: '2026-08-01', to: '2026-08-31' },
        });

        expect(formGet).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Apply' }),
        ).toBeInTheDocument();
    });

    /*
     * "This year" means nothing until you can see that this school's year runs
     * July to March. The pickers stay visible on a preset, disabled, showing
     * what it resolved to.
     */
    it('shows the dates a preset resolved to', () => {
        renderDashboard();

        expect(screen.getByLabelText('From')).toBeDisabled();
        expect(screen.getByLabelText('From')).toHaveTextContent('Jul 1, 2026');
    });
});

describe('an empty range', () => {
    it('invites an action rather than drawing an empty chart', () => {
        renderDashboard({
            summary: {
                ...makeProps().summary,
                income_centavos: 0,
                expenses_centavos: 0,
                net_income_centavos: 0,
                revenue_by_account: [],
            },
            monthlySeries: [],
        });

        expect(
            screen.getByText('Nothing posted in this range'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('No revenue in this range'),
        ).toBeInTheDocument();
    });

    it('says drafts do not count, because that is the usual surprise', () => {
        renderDashboard({
            summary: {
                ...makeProps().summary,
                income_centavos: 0,
                expenses_centavos: 0,
                revenue_by_account: [],
            },
            monthlySeries: [],
        });

        expect(screen.getByText(/Drafts are not counted/)).toBeInTheDocument();
    });
});

describe('revenue by account', () => {
    it('names the accounts the school configured', () => {
        // Never a hardcoded list of fee types — this is the chart of accounts.
        renderDashboard();

        expect(screen.getByText('Revenue by account')).toBeInTheDocument();
        expect(
            screen.getByText(/income accounts this school/),
        ).toBeInTheDocument();
    });
});

describe('after a preset reload', () => {
    /*
     * A preset's dates are resolved server-side — "this year" is whatever this
     * school's accounting periods say. `useForm` seeds itself once and the
     * reload uses `preserveState`, so reading the pickers from form state left
     * them showing the range the operator came from while the tiles showed the
     * new one. Caught in the browser: dates read Aug 1–31 beside figures for
     * July to September.
     */
    it('shows the range the server resolved, not the one last submitted', () => {
        renderDashboard({
            // What the operator left behind in form state...
            filters: {
                preset: 'quarter',
                from: '2026-07-01',
                to: '2026-09-30',
            },
        });

        expect(screen.getByLabelText('From')).toHaveTextContent('Jul 1, 2026');
        expect(screen.getByLabelText('To')).toHaveTextContent('Sep 30, 2026');
    });
});

/*
 * The bug this page shipped with: Custom unlocked nothing.
 *
 * `preset` was read from the SERVER's filters, but choosing Custom
 * deliberately does not reload — there is nothing to fetch until dates are
 * picked. So the server kept saying "year", the pickers stayed disabled, the
 * Apply button never rendered, and the range could not be changed at all.
 *
 * Two different questions had been collapsed onto one prop: which preset is
 * selected (a client choice, live on click) and which dates to display (the
 * server's, while a preset is active).
 */
describe('choosing a custom range', () => {
    function chooseCustom(): void {
        fireEvent.click(screen.getByRole('button', { name: 'Custom' }));
    }

    it('unlocks the date pickers as soon as Custom is chosen', () => {
        renderDashboard();

        expect(screen.getByLabelText('From')).toBeDisabled();

        chooseCustom();

        expect(screen.getByLabelText('From')).not.toBeDisabled();
        expect(screen.getByLabelText('To')).not.toBeDisabled();
    });

    it('offers Apply once a range can be composed', () => {
        renderDashboard();

        expect(
            screen.queryByRole('button', { name: 'Apply' }),
        ).not.toBeInTheDocument();

        chooseCustom();

        expect(
            screen.getByRole('button', { name: 'Apply' }),
        ).toBeInTheDocument();
    });

    it('does not reload until Apply is pressed', () => {
        // Reloading on the first of two dates would fetch a range nobody asked
        // for, and the second pick would race the response.
        renderDashboard();

        chooseCustom();

        expect(formGet).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

        expect(formGet).toHaveBeenCalledTimes(1);
    });

    it('marks Custom as the selected range', () => {
        renderDashboard();

        chooseCustom();

        // The other presets stay available; Custom is the active one.
        expect(screen.getByRole('button', { name: 'Custom' })).toBeEnabled();
        expect(screen.getByLabelText('From')).not.toBeDisabled();
    });
});
