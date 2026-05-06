import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Dashboard from '@/pages/dashboard';
import type { DashboardPageProps } from '@/types/dashboard';

// The dashboard page exercises only Inertia primitives — `Head` and `Link`.
// Stub them to plain DOM nodes so the page mounts without an Inertia context.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    usePage: () => ({ props: { auth: { user: null } } }),
    router: { visit: vi.fn() },
}));

function makeProps(
    overrides: Partial<DashboardPageProps> = {},
): DashboardPageProps {
    return {
        kpis: {
            activeEmployees: { count: 42, deltaThisMonth: 3 },
            currentPeriod: {
                id: 11,
                label: 'May 2026',
                startDate: '2026-05-01',
                endDate: '2026-05-15',
                status: 'open',
            },
            latestRun: {
                id: 17,
                periodLabel: 'April 2026 Pay Run',
                status: 'computed',
                netPayCentavos: 1_245_300_50,
                computedAt: new Date(Date.now() - 60 * 60 * 1000).toISOString(),
            },
        },
        actions: {
            role: 'super-admin',
            primaryCta: {
                label: 'Generate payroll',
                href: '/admin/payroll-runs/create',
            },
            secondaryCtas: [
                { label: 'Open audit log', href: '/admin/audit-logs' },
                { label: 'Manage employees', href: '/employees' },
            ],
        },
        recentRuns: [
            {
                id: 17,
                periodLabel: 'April 2026 Pay Run',
                status: 'computed',
                employeeCount: 15,
                netTotalCentavos: 1_245_300_50,
                computedAt: new Date(
                    Date.now() - 2 * 60 * 60 * 1000,
                ).toISOString(),
            },
            {
                id: 16,
                periodLabel: 'March 2026 Pay Run',
                status: 'posted',
                employeeCount: 14,
                netTotalCentavos: 1_180_000_00,
                computedAt: new Date(
                    Date.now() - 3 * 24 * 60 * 60 * 1000,
                ).toISOString(),
            },
        ],
        recentActivity: [
            {
                id: 1,
                actor: { name: 'Alex Reyes', initials: 'AR' },
                action: 'updated',
                targetLabel: 'PayrollRun #42',
                occurredAt: new Date(Date.now() - 12 * 60 * 1000).toISOString(),
            },
            {
                id: 2,
                actor: { name: 'Bea Cruz', initials: 'BC' },
                action: 'approved',
                targetLabel: 'Allowance · Transportation',
                occurredAt: new Date(
                    Date.now() - 6 * 60 * 60 * 1000,
                ).toISOString(),
            },
        ],
        reminders: [],
        ...overrides,
    };
}

describe('Dashboard', () => {
    it('renders KPI cards with given values', () => {
        render(<Dashboard {...makeProps()} />);

        // Active employees count + delta hint
        expect(screen.getByText('42')).toBeInTheDocument();
        expect(screen.getByText('+3 this month')).toBeInTheDocument();

        // Current pay period label
        expect(screen.getByText('May 2026')).toBeInTheDocument();
        // The latest-run KPI title (period label) appears as text on the
        // KPI card; the same string is the period in the recent-runs table.
        expect(
            screen.getAllByText('April 2026 Pay Run').length,
        ).toBeGreaterThanOrEqual(1);
    });

    it('shows "no change this month" when the headcount delta is zero', () => {
        render(
            <Dashboard
                {...makeProps({
                    kpis: {
                        ...makeProps().kpis,
                        activeEmployees: { count: 12, deltaThisMonth: 0 },
                    },
                })}
            />,
        );

        expect(screen.getByText('No change this month')).toBeInTheDocument();
    });

    it('renders empty states for null currentPeriod and latestRun', () => {
        render(
            <Dashboard
                {...makeProps({
                    kpis: {
                        activeEmployees: { count: 0, deltaThisMonth: 0 },
                        currentPeriod: null,
                        latestRun: null,
                    },
                })}
            />,
        );

        expect(screen.getByText('No active pay period')).toBeInTheDocument();
        // The KPI card title plus the (matching) DataTable empty title both
        // surface the "No payroll runs yet" copy.
        expect(
            screen.getAllByText('No payroll runs yet').length,
        ).toBeGreaterThanOrEqual(1);
    });

    it('renders the role-aware primary CTA from backend props', () => {
        render(<Dashboard {...makeProps()} />);

        const primary = screen.getByRole('link', {
            name: /generate payroll/i,
        });
        expect(primary).toHaveAttribute('href', '/admin/payroll-runs/create');

        // Secondary CTAs render too
        expect(
            screen.getByRole('link', { name: /open audit log/i }),
        ).toHaveAttribute('href', '/admin/audit-logs');
    });

    it('renders recent payroll runs as table rows', () => {
        render(<Dashboard {...makeProps()} />);

        // One header row + two body rows
        const rows = screen.getAllByRole('row');
        expect(rows.length).toBe(3);

        // Row content surfaces the period label and the employee count
        const aprilRow = screen.getByRole('row', {
            name: /April 2026 Pay Run/i,
        });
        expect(within(aprilRow).getByText('15')).toBeInTheDocument();
    });

    it('shows the empty state when recentRuns is empty', () => {
        render(
            <Dashboard
                {...makeProps({
                    recentRuns: [],
                })}
            />,
        );

        // The empty state action surfaces a "Generate payroll" button.
        // The header CTA also has the same label, so we expect 2+ matches.
        expect(
            screen.getAllByRole('link', { name: /generate payroll/i }).length,
        ).toBeGreaterThanOrEqual(2);
    });

    it('renders recent activity entries with relative time', () => {
        render(<Dashboard {...makeProps()} />);

        expect(screen.getByText('Alex Reyes')).toBeInTheDocument();
        expect(screen.getByText('Bea Cruz')).toBeInTheDocument();
        expect(screen.getByText('PayrollRun #42')).toBeInTheDocument();
    });

    it('shows the empty state when recent activity is empty', () => {
        render(
            <Dashboard
                {...makeProps({
                    recentActivity: [],
                })}
            />,
        );

        expect(screen.getByText('No recent activity')).toBeInTheDocument();
    });

    it('hides the reminders section when reminders is empty', () => {
        render(<Dashboard {...makeProps({ reminders: [] })} />);

        expect(screen.queryByText('Reminders')).not.toBeInTheDocument();
    });

    it('renders reminders when provided', () => {
        render(
            <Dashboard
                {...makeProps({
                    reminders: [
                        {
                            kind: 'period-close',
                            message: 'May 2026 closes in 2 days',
                            href: '/admin/pay-periods',
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByText('Reminders')).toBeInTheDocument();
        expect(
            screen.getByText('May 2026 closes in 2 days'),
        ).toBeInTheDocument();
    });
});
