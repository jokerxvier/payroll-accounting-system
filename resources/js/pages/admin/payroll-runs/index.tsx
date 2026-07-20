import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, FileText, Plus } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { PayrollRunStatusBadge } from '@/components/payroll-run-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    create as payrollRunsCreate,
    index as payrollRunsIndex,
    show as payrollRunsShow,
} from '@/routes/admin/payroll-runs';
import type { Paginator } from '@/types/pagination';
import type { PayrollRunSummary } from '@/types/payroll-run';

interface Props {
    runs: Paginator<PayrollRunSummary>;
    can: { create: boolean };
}

function formatCurrency(centavos: number): string {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(centavos / 100);
}

function formatDate(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function PayrollRunsIndex({ runs, can }: Props) {
    const goPage = (page: number) => {
        router.get(
            payrollRunsIndex().url,
            { page },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <>
            <Head title="Payroll runs" />
            <div className="space-y-6 p-4">
                <PageHeader
                    title="Payroll runs"
                    description="Generated payroll runs grouped most-recent first. Each run snapshots one employee's payslip per row."
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link href={payrollRunsCreate().url}>
                                    <Plus className="mr-1 h-4 w-4" />
                                    Generate payroll
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {runs.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-10">
                            <EmptyState
                                icon={FileText}
                                title="No payroll runs yet"
                                description="Generate the first run to compute payslips for the selected pay period."
                                action={
                                    can.create ? (
                                        <Button asChild>
                                            <Link
                                                href={payrollRunsCreate().url}
                                            >
                                                Generate payroll
                                            </Link>
                                        </Button>
                                    ) : undefined
                                }
                            />
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">
                                Recent runs
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto rounded-md border">
                                <Table className="text-sm">
                                    <TableHeader>
                                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Period
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Status
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Employees
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Total net pay
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Started
                                            </TableHead>
                                            <TableHead />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {runs.data.map((run) => (
                                            <TableRow
                                                key={run.id}
                                                className="transition-colors hover:bg-muted/30"
                                            >
                                                <TableCell className="font-mono text-xs">
                                                    {run.pay_period?.code ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <PayrollRunStatusBadge
                                                        status={run.status}
                                                    />
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {run.total_employees}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {formatCurrency(
                                                        run.total_net_pay_centavos,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground tabular-nums">
                                                    {formatDate(run.started_at)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Button
                                                        asChild
                                                        size="icon"
                                                        variant="ghost"
                                                        className="h-7 w-7"
                                                        aria-label={`Open payroll run ${run.id}`}
                                                    >
                                                        <Link
                                                            href={
                                                                payrollRunsShow(
                                                                    run.id,
                                                                ).url
                                                            }
                                                        >
                                                            <ChevronRight className="h-4 w-4" />
                                                        </Link>
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            {runs.last_page > 1 ? (
                                <div className="mt-4 flex items-center justify-between text-xs text-muted-foreground">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={runs.current_page === 1}
                                        onClick={() =>
                                            goPage(runs.current_page - 1)
                                        }
                                    >
                                        Previous
                                    </Button>
                                    <span className="tabular-nums">
                                        Page {runs.current_page} of{' '}
                                        {runs.last_page}
                                        {runs.total > 0 ? (
                                            <span className="ml-1">
                                                · {runs.total} runs
                                            </span>
                                        ) : null}
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            runs.current_page === runs.last_page
                                        }
                                        onClick={() =>
                                            goPage(runs.current_page + 1)
                                        }
                                    >
                                        Next
                                    </Button>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

PayrollRunsIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin/payroll-runs' },
        { title: 'Payroll runs', href: '/admin/payroll-runs' },
    ],
};
