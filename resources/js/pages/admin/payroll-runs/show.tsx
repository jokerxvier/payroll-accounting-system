import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, FileText, Loader2 } from 'lucide-react';
import { useEffect } from 'react';
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
import { index as payrollRunsIndex } from '@/routes/admin/payroll-runs';
import { PAYROLL_RUN_STATUS_LABELS } from '@/types/payroll-run';
import type {
    PayrollRunProgress,
    PayrollRunStatus,
    PayrollRunSummary,
    PayslipSummary,
} from '@/types/payroll-run';

interface Props {
    run: PayrollRunSummary;
    payslips: PayslipSummary[];
    progress: PayrollRunProgress;
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

function formatDateTime(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    return new Date(iso).toLocaleString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function PayrollRunShow({ run, payslips, progress }: Props) {
    // Poll the run state while computing — the per-employee batch jobs
    // persist payslips one at a time. Reload only `run`, `payslips`, and
    // `progress` so other shared props (auth, flash, etc.) don't churn.
    useEffect(() => {
        if (run.status !== 'computing') {
            return;
        }

        const handle = window.setInterval(() => {
            router.reload({ only: ['run', 'payslips', 'progress'] });
        }, 2000);

        return () => window.clearInterval(handle);
    }, [run.status]);

    const completionPct =
        progress.total_employees === 0
            ? 100
            : Math.min(
                  100,
                  Math.round(
                      (progress.persisted_payslips / progress.total_employees) *
                          100,
                  ),
              );

    return (
        <>
            <Head title={`Payroll run #${run.id}`} />
            <div className="space-y-6 p-4">
                <PageHeader
                    title={`Payroll run #${run.id}`}
                    description={
                        run.pay_period
                            ? `Period ${run.pay_period.code} (${run.pay_period.start_date} → ${run.pay_period.end_date})`
                            : ''
                    }
                    actions={
                        <div className="flex items-center gap-2">
                            <Badge variant={STATUS_VARIANT[run.status]}>
                                {PAYROLL_RUN_STATUS_LABELS[run.status]}
                            </Badge>
                            <Button asChild variant="outline">
                                <Link href={payrollRunsIndex().url}>
                                    <ArrowLeft className="mr-1 h-4 w-4" />
                                    Back
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {run.status === 'computing' ? (
                    <Card>
                        <CardContent className="space-y-3 py-6">
                            <div className="flex items-center gap-3 text-sm">
                                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                                <span>
                                    Computing payslips —{' '}
                                    <span className="tabular-nums">
                                        {progress.persisted_payslips}
                                    </span>
                                    {' / '}
                                    <span className="tabular-nums">
                                        {progress.total_employees}
                                    </span>{' '}
                                    employees
                                </span>
                                <span className="ml-auto text-muted-foreground tabular-nums">
                                    {completionPct}%
                                </span>
                            </div>
                            <div
                                className="h-2 w-full overflow-hidden rounded-full bg-muted"
                                role="progressbar"
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={completionPct}
                            >
                                <div
                                    className="h-full bg-primary transition-all"
                                    style={{ width: `${completionPct}%` }}
                                />
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard
                        label="Employees"
                        value={String(run.total_employees)}
                    />
                    <SummaryCard
                        label="Employee deductions"
                        value={formatCurrency(
                            run.total_employee_deductions_centavos,
                        )}
                    />
                    <SummaryCard
                        label="Employer contributions"
                        value={formatCurrency(
                            run.total_employer_contributions_centavos,
                        )}
                    />
                    <SummaryCard
                        label="Total net pay"
                        value={formatCurrency(run.total_net_pay_centavos)}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-medium">
                            Payslips
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {payslips.length === 0 ? (
                            <EmptyState
                                icon={FileText}
                                title="No payslips yet"
                                description={
                                    run.status === 'computing'
                                        ? 'Per-employee jobs are still running. This list updates automatically.'
                                        : 'No active employees were found at the time this run was created.'
                                }
                            />
                        ) : (
                            <div className="overflow-x-auto rounded-md border">
                                <Table className="text-sm">
                                    <TableHeader>
                                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Staff
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Name
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Gross
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Deductions
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Net
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Exemptions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {payslips.map((p) => (
                                            <TableRow key={p.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {p.lms_staff_id}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {p.staff_name ?? (
                                                        <span className="text-xs text-muted-foreground">
                                                            Unknown staff
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {formatCurrency(
                                                        p.gross_pay_centavos,
                                                    )}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {formatCurrency(
                                                        p.total_employee_deductions_centavos,
                                                    )}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {formatCurrency(
                                                        p.net_pay_centavos,
                                                    )}
                                                </TableCell>
                                                <TableCell className="space-x-1">
                                                    {p.applied_exemptions
                                                        .length === 0 ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            —
                                                        </span>
                                                    ) : (
                                                        p.applied_exemptions.map(
                                                            (code) => (
                                                                <Badge
                                                                    key={code}
                                                                    variant="outline"
                                                                    className="font-mono text-xs"
                                                                >
                                                                    {code}
                                                                </Badge>
                                                            ),
                                                        )
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-medium">
                            Audit
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                        <div>
                            <span className="font-medium">Created:</span>{' '}
                            {formatDateTime(run.created_at)}
                        </div>
                        <div>
                            <span className="font-medium">Started:</span>{' '}
                            {formatDateTime(run.started_at)}
                        </div>
                        <div>
                            <span className="font-medium">Computed:</span>{' '}
                            {formatDateTime(run.computed_at)}
                        </div>
                        <div>
                            <span className="font-medium">Approved:</span>{' '}
                            {formatDateTime(run.approved_at)}
                            {run.approved_by
                                ? ` by ${run.approved_by.name}`
                                : ''}
                        </div>
                        {run.voided_at ? (
                            <div className="sm:col-span-2">
                                <span className="font-medium">Voided:</span>{' '}
                                {formatDateTime(run.voided_at)}
                                {run.voided_by
                                    ? ` by ${run.voided_by.name}`
                                    : ''}
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
    return (
        <Card>
            <CardContent className="py-4">
                <p className="text-xs tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>
                <p className="mt-1 text-lg font-semibold tabular-nums">
                    {value}
                </p>
            </CardContent>
        </Card>
    );
}

PayrollRunShow.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin/payroll-runs' },
        { title: 'Payroll runs', href: '/admin/payroll-runs' },
        { title: 'Show', href: '/admin/payroll-runs' },
    ],
};
