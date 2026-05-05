import { Head, Link } from '@inertiajs/react';
import { ChevronRight, FileText, Plus } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
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
    show as payrollRunsShow,
} from '@/routes/admin/payroll-runs';
import { PAYROLL_RUN_STATUS_LABELS } from '@/types/payroll-run';
import type { PayrollRunStatus, PayrollRunSummary } from '@/types/payroll-run';

interface Props {
    runs: PayrollRunSummary[];
    can: { create: boolean };
}

const STATUS_VARIANT: Record<
    PayrollRunStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    draft: 'outline',
    computing: 'secondary',
    computed: 'default',
    pending_approval: 'secondary',
    approved: 'default',
    posted: 'default',
    voided: 'destructive',
};

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

                {runs.length === 0 ? (
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
                                        {runs.map((run) => (
                                            <TableRow
                                                key={run.id}
                                                className="transition-colors hover:bg-muted/30"
                                            >
                                                <TableCell className="font-mono text-xs">
                                                    {run.pay_period?.code ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            STATUS_VARIANT[
                                                                run.status
                                                            ]
                                                        }
                                                    >
                                                        {
                                                            PAYROLL_RUN_STATUS_LABELS[
                                                                run.status
                                                            ]
                                                        }
                                                    </Badge>
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
