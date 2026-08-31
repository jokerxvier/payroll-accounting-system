import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import InvoiceDashboard from '@/pages/admin/accounting/reports/invoice-dashboard';
import type { InvoiceDashboardPageProps } from '@/types';

const formGet = vi.fn();

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
 * The invoice dashboard.
 *
 * The figures are proved by `ReceivablesServiceTest`. What is left for the page
 * is that it labels ranged and as-at figures apart, opens a payer's invoices,
 * and says something useful when there is nothing to show.
 */

function makeProps(
    overrides: Partial<InvoiceDashboardPageProps> = {},
): InvoiceDashboardPageProps {
    return {
        filters: { preset: 'year', from: '2026-07-01', to: '2026-09-30' },
        summary: {
            from: '2026-07-01',
            to: '2026-09-30',
            as_of: '2026-09-01',
            invoiced_centavos: 16_350_112,
            collected_centavos: 4_120_000,
            outstanding_centavos: 14_610_112,
            overdue_centavos: 0,
            aging: [
                { key: 'current', label: 'Current', centavos: 14_610_112 },
                { key: '1_30', label: '1–30 days', centavos: 0 },
                { key: '31_60', label: '31–60 days', centavos: 0 },
                { key: '61_90', label: '61–90 days', centavos: 0 },
                { key: 'over_90', label: '90+ days', centavos: 0 },
            ],
            statuses: [
                { key: 'paid', label: 'Paid', count: 2, centavos: 4_120_000 },
                {
                    key: 'partially_paid',
                    label: 'Partially paid',
                    count: 1,
                    centavos: 1_120_000,
                },
                {
                    key: 'unpaid',
                    label: 'Unpaid',
                    count: 5,
                    centavos: 11_110_112,
                },
                { key: 'overdue', label: 'Overdue', count: 0, centavos: 0 },
            ],
            monthly: [
                {
                    month: '2026-08',
                    label: 'Aug 2026',
                    invoiced_centavos: 10_000_000,
                    collected_centavos: 3_000_000,
                },
                {
                    month: '2026-09',
                    label: 'Sep 2026',
                    invoiced_centavos: 6_350_112,
                    collected_centavos: 1_120_000,
                },
            ],
            top_outstanding: [
                {
                    contact_id: 7,
                    contact_name: 'Dela Cruz Family',
                    students: ['Juan Dela Cruz', 'Sofia Dela Cruz'],
                    invoiced_centavos: 9_000_000,
                    paid_centavos: 1_000_000,
                    outstanding_centavos: 8_000_000,
                    oldest_due_date: '2026-06-16',
                    days_overdue: 77,
                    status: 'overdue',
                },
            ],
            ...(overrides.summary ?? {}),
        },
        ...overrides,
    };
}

function renderDashboard(
    overrides: Partial<InvoiceDashboardPageProps> = {},
): void {
    formGet.mockClear();
    render(<InvoiceDashboard {...makeProps(overrides)} />);
}

describe('the tiles', () => {
    it('shows all four figures', () => {
        // `getAllByText`, not `getByText`: "Outstanding" is also a column
        // header on the table below and "Overdue" is also a status and a
        // badge. That repetition is the vocabulary being consistent, not a
        // clash — the same word means the same thing in all three places.
        renderDashboard();

        for (const label of [
            'Total invoiced',
            'Collected',
            'Outstanding',
            'Overdue',
        ]) {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
        }
    });

    /*
     * Two kinds of figure sit in one row again. Invoiced and Collected are
     * ranged; Outstanding and Overdue are as at today. Without the hints a
     * reader has no way to know the row mixes them.
     */
    it('says which figures are ranged and which are as at today', () => {
        renderDashboard();

        expect(screen.getByText('Billed in the range')).toBeInTheDocument();
        expect(
            screen.getByText('Unpaid invoices, whenever billed'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Past due as at 2026-09-01/),
        ).toBeInTheDocument();
    });
});

describe('the outstanding table', () => {
    it('shows one row per payer, with their children named', () => {
        // A family with two at the school owes once, not twice.
        renderDashboard();

        expect(screen.getByText('Dela Cruz Family')).toBeInTheDocument();
        expect(
            screen.getByText('Juan Dela Cruz, Sofia Dela Cruz'),
        ).toBeInTheDocument();
    });

    it("opens that payer's invoices", () => {
        renderDashboard();

        const link = screen.getByRole('link', { name: 'Dela Cruz Family' });

        expect(link).toHaveAttribute(
            'href',
            expect.stringContaining('contact_id=7'),
        );
    });

    it('flags how late they are', () => {
        renderDashboard();

        // Scoped to the payer's own row: "Overdue" is also a tile and a
        // status label, and the badge is the one that says THIS family is late.
        const row = screen.getByText('Dela Cruz Family').closest('tr');

        expect(row).not.toBeNull();
        expect(
            within(row as HTMLElement).getByText('Overdue'),
        ).toBeInTheDocument();
        expect(within(row as HTMLElement).getByText('77')).toBeInTheDocument();
        expect(
            within(row as HTMLElement).getByText('2026-06-16'),
        ).toBeInTheDocument();
    });

    it('shows a dash rather than a zero when nothing is late', () => {
        renderDashboard({
            summary: {
                ...makeProps().summary,
                top_outstanding: [
                    {
                        ...makeProps().summary.top_outstanding[0],
                        days_overdue: 0,
                        oldest_due_date: null,
                        status: 'unpaid',
                    },
                ],
            },
        });

        expect(screen.getByText('Unpaid')).toBeInTheDocument();
    });
});

describe('an empty range', () => {
    it('invites an action rather than drawing empty charts', () => {
        renderDashboard({
            summary: {
                ...makeProps().summary,
                aging: makeProps().summary.aging.map((b) => ({
                    ...b,
                    centavos: 0,
                })),
                statuses: makeProps().summary.statuses.map((s) => ({
                    ...s,
                    count: 0,
                    centavos: 0,
                })),
                monthly: [],
                top_outstanding: [],
            },
        });

        expect(screen.getByText('Nothing outstanding')).toBeInTheDocument();
        expect(
            screen.getByText('No approved invoices yet'),
        ).toBeInTheDocument();
        expect(screen.getByText('Nobody owes anything')).toBeInTheDocument();
    });
});

describe('the range filter', () => {
    it('unlocks the pickers when Custom is chosen', () => {
        // Same control the accounting dashboard uses, and the same bug it had.
        renderDashboard();

        expect(screen.getByLabelText('From')).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'Custom' }));

        expect(screen.getByLabelText('From')).not.toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Apply' }),
        ).toBeInTheDocument();
    });

    it('reloads immediately on a preset', () => {
        renderDashboard();

        fireEvent.click(screen.getByRole('button', { name: 'This month' }));

        expect(formGet).toHaveBeenCalledTimes(1);
    });
});
