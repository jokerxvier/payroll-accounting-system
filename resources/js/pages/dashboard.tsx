import { Head, Link, router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import {
    AlertCircle,
    BellRing,
    CalendarClock,
    CalendarDays,
    ChevronRight,
    FileText,
    Plus,
    Receipt,
    Users,
    Wallet,
} from 'lucide-react';
import { useMemo } from 'react';
import { Money } from '@/components/money';
import { PageHeader } from '@/components/page-header';
import { PayrollRunStatusBadge } from '@/components/payroll-run-status-badge';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import { dashboard } from '@/routes';
import type { Paginator } from '@/types';
import type {
    DashboardPageProps,
    DashboardRecentRun,
    DashboardReminder,
} from '@/types/dashboard';

const PERIOD_STATUS_VARIANT: Record<
    'draft' | 'open' | 'closed',
    'default' | 'secondary' | 'outline'
> = {
    draft: 'secondary',
    open: 'default',
    closed: 'outline',
};

const PERIOD_STATUS_LABEL: Record<'draft' | 'open' | 'closed', string> = {
    draft: 'Draft',
    open: 'Open',
    closed: 'Closed',
};

const REMINDER_ICON: Record<DashboardReminder['kind'], typeof BellRing> = {
    'period-close': CalendarClock,
    'contribution-table-change': AlertCircle,
    'loan-payoff': Receipt,
};

function formatShortRange(startIso: string, endIso: string): string {
    const fmt = new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: 'numeric',
    });
    // Date strings come in as 'YYYY-MM-DD'. Construct as local-time so the
    // PHT user doesn't see a UTC-shifted day.
    const start = new Date(`${startIso}T00:00:00`);
    const end = new Date(`${endIso}T00:00:00`);

    return `${fmt.format(start)} – ${fmt.format(end)}`;
}

function formatRelativeTime(iso: string | null): string {
    if (iso === null || iso === '') {
        return '—';
    }

    const target = new Date(iso).getTime();

    if (Number.isNaN(target)) {
        return '—';
    }

    const diffSeconds = Math.round((target - Date.now()) / 1000);
    const abs = Math.abs(diffSeconds);
    const rtf = new Intl.RelativeTimeFormat('en-PH', { numeric: 'auto' });

    if (abs < 60) {
        return rtf.format(diffSeconds, 'second');
    }

    if (abs < 3600) {
        return rtf.format(Math.round(diffSeconds / 60), 'minute');
    }

    if (abs < 86_400) {
        return rtf.format(Math.round(diffSeconds / 3600), 'hour');
    }

    if (abs < 2_592_000) {
        return rtf.format(Math.round(diffSeconds / 86_400), 'day');
    }

    if (abs < 31_536_000) {
        return rtf.format(Math.round(diffSeconds / 2_592_000), 'month');
    }

    return rtf.format(Math.round(diffSeconds / 31_536_000), 'year');
}

/**
 * Static-array adapter — `<DataTable>` expects a `Paginator<T>` because it
 * owns server-side pagination. The dashboard ships a small fixed slice, so
 * we wrap it in a single-page paginator. `DataTablePagination` short-circuits
 * to null when `last_page <= 1`, so no UI clutter.
 */
function singlePagePaginator<T>(rows: T[]): Paginator<T> {
    return {
        data: rows,
        current_page: 1,
        last_page: 1,
        per_page: rows.length,
        from: rows.length === 0 ? null : 1,
        to: rows.length === 0 ? null : rows.length,
        total: rows.length,
        links: [],
    };
}

export default function Dashboard({
    kpis,
    actions,
    recentRuns,
    recentActivity,
    reminders,
}: DashboardPageProps) {
    const runColumns = useMemo<ColumnDef<DashboardRecentRun>[]>(
        () => [
            {
                accessorKey: 'periodLabel',
                header: 'Period',
                cell: ({ row }) => (
                    <span className="font-medium">
                        {row.original.periodLabel}
                    </span>
                ),
            },
            {
                accessorKey: 'status',
                header: 'Status',
                cell: ({ row }) => (
                    <PayrollRunStatusBadge status={row.original.status} />
                ),
            },
            {
                accessorKey: 'employeeCount',
                header: 'Employees',
                meta: { align: 'right' },
                cell: ({ row }) => row.original.employeeCount,
            },
            {
                accessorKey: 'netTotalCentavos',
                header: 'Net total',
                meta: { align: 'right' },
                cell: ({ row }) => (
                    <Money amount={row.original.netTotalCentavos / 100} />
                ),
            },
            {
                accessorKey: 'computedAt',
                header: 'Computed',
                cell: ({ row }) => (
                    <span className="text-muted-foreground">
                        {formatRelativeTime(row.original.computedAt)}
                    </span>
                ),
            },
            {
                id: 'actions',
                header: '',
                meta: { align: 'right', cellClassName: 'w-12' },
                cell: () => (
                    <ChevronRight
                        className="ml-auto h-4 w-4 text-muted-foreground"
                        aria-hidden
                    />
                ),
            },
        ],
        [],
    );

    const runsPaginator = useMemo(
        () => singlePagePaginator(recentRuns),
        [recentRuns],
    );

    const activeDelta = kpis.activeEmployees.deltaThisMonth;
    const activeDeltaLabel =
        activeDelta === 0
            ? 'No change this month'
            : activeDelta > 0
              ? `+${activeDelta} this month`
              : `${activeDelta} this month`;

    return (
        <>
            <Head title="Dashboard" />
            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="OVERVIEW"
                    title="Dashboard"
                    description="At-a-glance snapshot of headcount, the active pay period, and the latest payroll activity."
                />

                {/* Section 1 — KPI strip */}
                <div className="grid gap-4 md:grid-cols-3">
                    <Link
                        href="/employees"
                        className="rounded-xl outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label="View employees"
                    >
                        <Card className="h-full transition-shadow hover:shadow-md">
                            <CardHeader className="pb-2">
                                <div className="flex items-center justify-between gap-2">
                                    <CardDescription>
                                        Active employees
                                    </CardDescription>
                                    <Users
                                        className="h-4 w-4 text-muted-foreground"
                                        aria-hidden
                                    />
                                </div>
                                <CardTitle className="font-serif text-3xl tabular-nums">
                                    {kpis.activeEmployees.count.toLocaleString(
                                        'en-PH',
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-xs text-muted-foreground">
                                {activeDeltaLabel}
                            </CardContent>
                        </Card>
                    </Link>

                    {kpis.currentPeriod === null ? (
                        <Link
                            href="/admin/pay-periods"
                            className="rounded-xl outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label="Manage pay periods"
                        >
                            <Card className="h-full transition-shadow hover:shadow-md">
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <CardDescription>
                                            Current pay period
                                        </CardDescription>
                                        <CalendarDays
                                            className="h-4 w-4 text-muted-foreground"
                                            aria-hidden
                                        />
                                    </div>
                                    <CardTitle className="font-serif text-2xl">
                                        No active pay period
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="text-xs text-muted-foreground">
                                    Open the pay periods admin to define one.
                                </CardContent>
                            </Card>
                        </Link>
                    ) : (
                        <Link
                            href="/admin/pay-periods"
                            className="rounded-xl outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label={`Open pay period ${kpis.currentPeriod.label}`}
                        >
                            <Card className="h-full transition-shadow hover:shadow-md">
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <CardDescription>
                                            Current pay period
                                        </CardDescription>
                                        <Badge
                                            variant={
                                                PERIOD_STATUS_VARIANT[
                                                    kpis.currentPeriod.status
                                                ]
                                            }
                                        >
                                            {
                                                PERIOD_STATUS_LABEL[
                                                    kpis.currentPeriod.status
                                                ]
                                            }
                                        </Badge>
                                    </div>
                                    <CardTitle className="font-serif text-2xl tracking-tight">
                                        {kpis.currentPeriod.label}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="text-xs text-muted-foreground tabular-nums">
                                    {formatShortRange(
                                        kpis.currentPeriod.startDate,
                                        kpis.currentPeriod.endDate,
                                    )}
                                </CardContent>
                            </Card>
                        </Link>
                    )}

                    {kpis.latestRun === null ? (
                        <Link
                            href="/admin/payroll-runs/create"
                            className="rounded-xl outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label="Create the first payroll run"
                        >
                            <Card className="h-full transition-shadow hover:shadow-md">
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <CardDescription>
                                            Latest payroll run
                                        </CardDescription>
                                        <Wallet
                                            className="h-4 w-4 text-muted-foreground"
                                            aria-hidden
                                        />
                                    </div>
                                    <CardTitle className="font-serif text-2xl">
                                        No payroll runs yet
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="text-xs text-muted-foreground">
                                    Generate the first run to populate this
                                    card.
                                </CardContent>
                            </Card>
                        </Link>
                    ) : (
                        <Link
                            href={`/admin/payroll-runs/${kpis.latestRun.id}`}
                            className="rounded-xl outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label={`Open payroll run for ${kpis.latestRun.periodLabel}`}
                        >
                            <Card className="h-full transition-shadow hover:shadow-md">
                                <CardHeader className="pb-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <CardDescription>
                                            Latest payroll run
                                        </CardDescription>
                                        <Wallet
                                            className="h-4 w-4 text-muted-foreground"
                                            aria-hidden
                                        />
                                    </div>
                                    <CardTitle className="font-serif text-3xl tabular-nums">
                                        <Money
                                            amount={
                                                kpis.latestRun.netPayCentavos /
                                                100
                                            }
                                        />
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    <span className="font-medium text-foreground">
                                        {kpis.latestRun.periodLabel}
                                    </span>
                                    <PayrollRunStatusBadge
                                        status={kpis.latestRun.status}
                                    />
                                    <span>
                                        {formatRelativeTime(
                                            kpis.latestRun.computedAt,
                                        )}
                                    </span>
                                </CardContent>
                            </Card>
                        </Link>
                    )}
                </div>

                {/* Section 2 — Action panel */}
                <div className="flex flex-wrap items-center gap-2">
                    <Button asChild size="lg">
                        <Link href={actions.primaryCta.href}>
                            <Plus className="mr-2 h-4 w-4" aria-hidden />
                            {actions.primaryCta.label}
                        </Link>
                    </Button>
                    {actions.secondaryCtas.map((cta) => (
                        <Button
                            key={`${cta.label}-${cta.href}`}
                            asChild
                            variant="outline"
                        >
                            <Link href={cta.href}>{cta.label}</Link>
                        </Button>
                    ))}
                </div>

                {/* Section 3 — Two-column body */}
                <div className="grid gap-6 lg:grid-cols-5">
                    <Card className="lg:col-span-3">
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recent payroll runs
                            </CardTitle>
                            <CardDescription>
                                The five most recent runs across all pay
                                periods.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <DataTable
                                columns={runColumns}
                                data={recentRuns}
                                paginator={runsPaginator}
                                onPageChange={() => {}}
                                density="compact"
                                onRowClick={(row) => {
                                    router.visit(
                                        `/admin/payroll-runs/${row.id}`,
                                    );
                                }}
                                rowKey={(row) => row.id}
                                empty={{
                                    icon: FileText,
                                    title: 'No payroll runs yet',
                                    description:
                                        'Generate the first run to compute payslips for the current pay period.',
                                    action: (
                                        <Button asChild>
                                            <Link href="/admin/payroll-runs/create">
                                                Generate payroll
                                            </Link>
                                        </Button>
                                    ),
                                }}
                            />
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recent activity
                            </CardTitle>
                            <CardDescription>
                                Latest changes across employees, allowances, and
                                payroll runs.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {recentActivity.length === 0 ? (
                                <p className="py-6 text-center text-sm text-muted-foreground">
                                    No recent activity
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {recentActivity.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                                        >
                                            <Avatar className="h-8 w-8 flex-shrink-0">
                                                <AvatarFallback className="text-xs font-medium">
                                                    {entry.actor.initials}
                                                </AvatarFallback>
                                            </Avatar>
                                            <div className="min-w-0 flex-1 space-y-0.5">
                                                <p className="text-sm leading-snug">
                                                    <span className="font-medium text-foreground">
                                                        {entry.actor.name}
                                                    </span>{' '}
                                                    <span className="text-muted-foreground">
                                                        {entry.action}
                                                    </span>{' '}
                                                    <span className="text-foreground">
                                                        {entry.targetLabel}
                                                    </span>
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatRelativeTime(
                                                        entry.occurredAt === ''
                                                            ? null
                                                            : entry.occurredAt,
                                                    )}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Section 4 — Reminders (only when non-empty) */}
                {reminders.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <BellRing className="h-4 w-4" aria-hidden />
                                Reminders
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="divide-y">
                                {reminders.map((reminder, idx) => {
                                    const Icon = REMINDER_ICON[reminder.kind];

                                    return (
                                        <li
                                            key={`${reminder.kind}-${idx}`}
                                            className="py-3 first:pt-0 last:pb-0"
                                        >
                                            <Link
                                                href={reminder.href}
                                                className="flex items-start gap-3 rounded-md outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                <Icon
                                                    className="mt-0.5 h-4 w-4 flex-shrink-0 text-muted-foreground"
                                                    aria-hidden
                                                />
                                                <span className="flex-1 text-sm">
                                                    {reminder.message}
                                                </span>
                                                <ChevronRight
                                                    className="h-4 w-4 flex-shrink-0 text-muted-foreground"
                                                    aria-hidden
                                                />
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
