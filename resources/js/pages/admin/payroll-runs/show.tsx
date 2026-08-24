import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    Ban,
    ChevronDown,
    ChevronRight,
    Download,
    FileText,
    Loader2,
    Lock,
    ReceiptText,
    Trash2,
} from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { show as journalShow } from '@/routes/admin/journal-entries';
import {
    destroy as payrollRunsDestroy,
    index as payrollRunsIndex,
    show as payrollRunsShow,
} from '@/routes/admin/payroll-runs';
import {
    build as bulkPdfsBuild,
    download as bulkPdfsDownload,
} from '@/routes/admin/payroll-runs/bulk-pdfs';
import { show as payslipShow } from '@/routes/admin/payroll-runs/payslips';
import type { Paginator } from '@/types/pagination';
import { PAYROLL_RUN_STATUS_LABELS } from '@/types/payroll-run';
import type {
    PayrollRunCanFlags,
    PayrollRunProgress,
    PayrollRunStatus,
    PayrollRunSummary,
    PayslipAuditLine,
    PayslipSummary,
} from '@/types/payroll-run';

interface Props {
    run: PayrollRunSummary;
    payslips: Paginator<PayslipSummary>;
    progress: PayrollRunProgress;
    can: PayrollRunCanFlags;
}

type DialogKind = 'submit' | 'approve' | 'post' | 'void' | null;

interface DialogConfig {
    title: string;
    description: string;
    confirmLabel: string;
    confirmVariant: 'default' | 'destructive';
    path: (id: number) => string;
}

const DIALOGS: Record<Exclude<DialogKind, null>, DialogConfig> = {
    submit: {
        title: 'Submit run for approval?',
        description:
            'The run will move to Pending approval. An approver can then sign off or void it.',
        confirmLabel: 'Submit for approval',
        confirmVariant: 'default',
        path: (id) => `/admin/payroll-runs/${id}/submit`,
    },
    approve: {
        title: 'Approve this payroll run?',
        description:
            'Approving locks the run. You can still void it, but you will need to re-run on a new period if you need to recompute.',
        confirmLabel: 'Approve',
        confirmVariant: 'default',
        path: (id) => `/admin/payroll-runs/${id}/approve`,
    },
    post: {
        title: 'Post this payroll run?',
        description:
            'Posting is the final state. After posting, this run cannot be edited or voided. Continue?',
        confirmLabel: 'Post run',
        confirmVariant: 'default',
        path: (id) => `/admin/payroll-runs/${id}/post`,
    },
    void: {
        title: 'Void this payroll run?',
        description:
            'Voiding marks the run as inactive. Payslip rows stay visible for audit. The pay period will be available for a new run after voiding.',
        confirmLabel: 'Void run',
        confirmVariant: 'destructive',
        path: (id) => `/admin/payroll-runs/${id}/void`,
    },
};

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

export default function PayrollRunShow({
    run,
    payslips,
    progress,
    can,
}: Props) {
    const [dialog, setDialog] = useState<DialogKind>(null);
    const [submitting, setSubmitting] = useState(false);
    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [buildingZip, setBuildingZip] = useState(false);
    // The ZIP build is a queued Bus::batch — the POST returns immediately,
    // so we snapshot the pre-build timestamp and watch for it to advance.
    const zipBaselineRef = useRef<string | null>(null);
    // Type-to-confirm delete. The destructive action only fires once the
    // operator types the exact confirmation word.
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteConfirm, setDeleteConfirm] = useState('');
    const [deleting, setDeleting] = useState(false);
    const deleteReady = deleteConfirm.trim().toLowerCase() === 'delete';

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

    // Poll the run while a bulk-ZIP build is in flight. The queued batch
    // stamps `bulk_pdf_built_at` on success, so completion is detected when
    // the timestamp advances past the value captured at kick-off. A 2-minute
    // cap keeps a failed batch (which never stamps) from spinning forever.
    useEffect(() => {
        if (!buildingZip) {
            return;
        }

        if (
            run.bulk_pdf_built_at &&
            run.bulk_pdf_built_at !== zipBaselineRef.current
        ) {
            setBuildingZip(false);

            return;
        }

        let elapsed = 0;
        const handle = window.setInterval(() => {
            elapsed += 2000;

            if (elapsed >= 120000) {
                setBuildingZip(false);
                window.clearInterval(handle);

                return;
            }

            router.reload({ only: ['run'] });
        }, 2000);

        return () => window.clearInterval(handle);
    }, [buildingZip, run.bulk_pdf_built_at]);

    const buildBulkZip = () => {
        zipBaselineRef.current = run.bulk_pdf_built_at;
        setBuildingZip(true);
        router.post(bulkPdfsBuild(run.id).url, {}, { preserveScroll: true });
    };

    const goPage = (page: number) => {
        router.get(
            payrollRunsShow(run.id).url,
            { page },
            { preserveScroll: true },
        );
    };

    const runDelete = () => {
        if (!deleteReady) {
            return;
        }

        setDeleting(true);
        router.delete(payrollRunsDestroy(run.id).url, {
            preserveScroll: true,
            onFinish: () => {
                setDeleting(false);
                setDeleteOpen(false);
            },
        });
    };

    const confirmDialog = () => {
        if (dialog === null) {
            return;
        }

        setSubmitting(true);
        router.post(
            DIALOGS[dialog].path(run.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setSubmitting(false);
                    setDialog(null);
                },
            },
        );
    };

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
                    backHref={payrollRunsIndex().url}
                    icon={ReceiptText}
                    title={`Payroll run #${run.id}`}
                    description={
                        run.pay_period
                            ? `Period ${run.pay_period.code} (${run.pay_period.start_date} → ${run.pay_period.end_date})`
                            : ''
                    }
                    actions={
                        <>
                            <Badge variant={STATUS_VARIANT[run.status]}>
                                {run.is_locked ? (
                                    <Lock className="mr-1 h-3 w-3" />
                                ) : null}
                                {PAYROLL_RUN_STATUS_LABELS[run.status]}
                            </Badge>
                            {/* DEMO: the submit → approve steps are bypassed —
                                a computed run posts in one click. The Submit and
                                Approve buttons (can.submit / can.approve) are
                                intentionally hidden; restore them to bring back
                                the maker-checker flow. */}
                            {can.post ? (
                                <Button onClick={() => setDialog('post')}>
                                    <Lock className="mr-1 h-4 w-4" />
                                    Post
                                </Button>
                            ) : null}
                            {can.void ? (
                                <Button
                                    variant="outline"
                                    className="text-destructive"
                                    onClick={() => setDialog('void')}
                                >
                                    <Ban className="mr-1 h-4 w-4" />
                                    Void
                                </Button>
                            ) : null}
                            {can.delete ? (
                                <Button
                                    variant="destructive"
                                    onClick={() => {
                                        setDeleteConfirm('');
                                        setDeleteOpen(true);
                                    }}
                                >
                                    <Trash2 className="mr-1 h-4 w-4" />
                                    Delete
                                </Button>
                            ) : null}
                        </>
                    }
                />

                <AlertDialog
                    open={dialog !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setDialog(null);
                        }
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                {dialog ? DIALOGS[dialog].title : ''}
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                {dialog ? DIALOGS[dialog].description : ''}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                disabled={submitting}
                                className={
                                    dialog &&
                                    DIALOGS[dialog].confirmVariant ===
                                        'destructive'
                                        ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90'
                                        : undefined
                                }
                                onClick={(e) => {
                                    e.preventDefault();
                                    confirmDialog();
                                }}
                            >
                                {submitting
                                    ? 'Working…'
                                    : dialog
                                      ? DIALOGS[dialog].confirmLabel
                                      : ''}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                <AlertDialog
                    open={deleteOpen}
                    onOpenChange={(open) => {
                        if (!open) {
                            setDeleteOpen(false);
                            setDeleteConfirm('');
                        }
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Delete payroll run #{run.id}?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                This permanently deletes the run and all of its
                                payslips. This cannot be undone. Type{' '}
                                <span className="font-semibold">delete</span> to
                                confirm.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="delete-confirm" className="sr-only">
                                Type delete to confirm
                            </Label>
                            <Input
                                id="delete-confirm"
                                value={deleteConfirm}
                                onChange={(e) =>
                                    setDeleteConfirm(e.target.value)
                                }
                                placeholder="delete"
                                autoComplete="off"
                                autoFocus
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' && deleteReady) {
                                        e.preventDefault();
                                        runDelete();
                                    }
                                }}
                            />
                        </div>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                disabled={!deleteReady || deleting}
                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                onClick={(e) => {
                                    e.preventDefault();
                                    runDelete();
                                }}
                            >
                                {deleting ? 'Deleting…' : 'Delete run'}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

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
                    <CardHeader className="flex flex-row items-center justify-between gap-2">
                        <CardTitle className="text-sm font-medium">
                            Payslips
                        </CardTitle>
                        {payslips.data.length > 0 ? (
                            <div className="flex items-center gap-2">
                                {run.has_bulk_pdf ? (
                                    <Button asChild size="sm" variant="outline">
                                        <a href={bulkPdfsDownload(run.id).url}>
                                            <Download className="mr-1 h-4 w-4" />
                                            Download all (ZIP)
                                        </a>
                                    </Button>
                                ) : null}
                                <Button
                                    size="sm"
                                    variant={
                                        run.has_bulk_pdf ? 'ghost' : 'outline'
                                    }
                                    disabled={buildingZip}
                                    onClick={buildBulkZip}
                                >
                                    {buildingZip ? (
                                        <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                                    ) : (
                                        <Archive className="mr-1 h-4 w-4" />
                                    )}
                                    {buildingZip
                                        ? 'Building ZIP…'
                                        : run.has_bulk_pdf
                                          ? 'Rebuild ZIP'
                                          : 'Build ZIP'}
                                </Button>
                            </div>
                        ) : null}
                    </CardHeader>
                    <CardContent>
                        {payslips.data.length === 0 ? (
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
                                            <TableHead className="w-[30px]" />
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
                                        {payslips.data.map((p) => {
                                            const isExpanded =
                                                expandedId === p.id;

                                            return (
                                                <Fragment key={p.id}>
                                                    <TableRow
                                                        className="cursor-pointer transition-colors hover:bg-muted/30"
                                                        onClick={() =>
                                                            setExpandedId(
                                                                isExpanded
                                                                    ? null
                                                                    : p.id,
                                                            )
                                                        }
                                                    >
                                                        <TableCell className="text-muted-foreground">
                                                            {isExpanded ? (
                                                                <ChevronDown className="h-4 w-4" />
                                                            ) : (
                                                                <ChevronRight className="h-4 w-4" />
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="font-mono text-xs">
                                                            {p.lms_staff_id}
                                                        </TableCell>
                                                        <TableCell className="text-sm">
                                                            {p.staff_name ?? (
                                                                <span className="text-xs text-muted-foreground">
                                                                    Unknown
                                                                    staff
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
                                                            {p
                                                                .applied_exemptions
                                                                .length ===
                                                            0 ? (
                                                                <span className="text-xs text-muted-foreground">
                                                                    —
                                                                </span>
                                                            ) : (
                                                                p.applied_exemptions.map(
                                                                    (code) => (
                                                                        <Badge
                                                                            key={
                                                                                code
                                                                            }
                                                                            variant="outline"
                                                                            className="font-mono text-xs"
                                                                        >
                                                                            {
                                                                                code
                                                                            }
                                                                        </Badge>
                                                                    ),
                                                                )
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                    {isExpanded ? (
                                                        <TableRow className="bg-muted/20 hover:bg-muted/20">
                                                            <TableCell
                                                                colSpan={7}
                                                                className="p-0"
                                                            >
                                                                <PayslipBreakdown
                                                                    runId={
                                                                        run.id
                                                                    }
                                                                    payslip={p}
                                                                />
                                                            </TableCell>
                                                        </TableRow>
                                                    ) : null}
                                                </Fragment>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}

                        {payslips.last_page > 1 ? (
                            <div className="mt-4 flex items-center justify-between text-xs text-muted-foreground">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={payslips.current_page === 1}
                                    onClick={() =>
                                        goPage(payslips.current_page - 1)
                                    }
                                >
                                    Previous
                                </Button>
                                <span className="tabular-nums">
                                    Page {payslips.current_page} of{' '}
                                    {payslips.last_page} · {payslips.total}{' '}
                                    payslips
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        payslips.current_page ===
                                        payslips.last_page
                                    }
                                    onClick={() =>
                                        goPage(payslips.current_page + 1)
                                    }
                                >
                                    Next
                                </Button>
                            </div>
                        ) : null}
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
                            <span className="font-medium">Submitted:</span>{' '}
                            {formatDateTime(run.submitted_at)}
                            {run.submitted_by
                                ? ` by ${run.submitted_by.name}`
                                : ''}
                        </div>
                        <div>
                            <span className="font-medium">Approved:</span>{' '}
                            {formatDateTime(run.approved_at)}
                            {run.approved_by
                                ? ` by ${run.approved_by.name}`
                                : ''}
                        </div>
                        <div>
                            <span className="font-medium">Posted:</span>{' '}
                            {formatDateTime(run.posted_at)}
                            {run.posted_by ? ` by ${run.posted_by.name}` : ''}
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
                        {run.status === 'posted' ? (
                            <div className="sm:col-span-2">
                                <span className="font-medium">Ledger:</span>{' '}
                                {run.journal_entry ? (
                                    <Link
                                        href={
                                            journalShow({
                                                journalEntry:
                                                    run.journal_entry.id,
                                            }).url
                                        }
                                        className="font-mono underline underline-offset-4"
                                    >
                                        {run.journal_entry.entry_number}
                                    </Link>
                                ) : (
                                    // A posted run with no entry is a real
                                    // state, not a failure of the run: the
                                    // ledger posting is refused while the
                                    // accounting period is closed, and
                                    // payroll is deliberately not blocked on
                                    // that. Say so rather than leaving a
                                    // blank that reads as "up to date".
                                    <span className="text-muted-foreground">
                                        Not posted to the ledger — the
                                        accounting period was closed or an
                                        account mapping is missing. Reopen the
                                        period and post the run again to retry.
                                    </span>
                                )}
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

const BUCKET_LABELS: Record<PayslipAuditLine['bucket'], string> = {
    earning: 'Earnings',
    employee_deduction: 'Employee deductions',
    employer_contribution: 'Employer contributions',
};

const BUCKET_ORDER: Array<PayslipAuditLine['bucket']> = [
    'earning',
    'employee_deduction',
    'employer_contribution',
];

function PayslipBreakdown({
    runId,
    payslip,
}: {
    runId: number;
    payslip: PayslipSummary;
}) {
    const grouped: Record<PayslipAuditLine['bucket'], PayslipAuditLine[]> = {
        earning: [],
        employee_deduction: [],
        employer_contribution: [],
    };

    for (const line of payslip.audit_lines) {
        grouped[line.bucket].push(line);
    }

    return (
        <div className="space-y-4 p-4">
            <div className="flex justify-end">
                <Button asChild size="sm" variant="outline">
                    <Link
                        href={
                            payslipShow({
                                payrollRun: runId,
                                payslip: payslip.id,
                            }).url
                        }
                    >
                        Open full payslip
                    </Link>
                </Button>
            </div>
            <div className="grid gap-4 lg:grid-cols-3">
                {BUCKET_ORDER.map((bucket) => (
                    <BucketCard
                        key={bucket}
                        title={BUCKET_LABELS[bucket]}
                        lines={grouped[bucket]}
                    />
                ))}
            </div>

            <div className="grid grid-cols-2 gap-3 rounded-md border bg-card p-3 sm:grid-cols-4">
                <TotalsCell
                    label="Gross pay"
                    value={formatCurrency(payslip.gross_pay_centavos)}
                />
                <TotalsCell
                    label="Taxable income"
                    value={formatCurrency(payslip.taxable_income_centavos)}
                />
                <TotalsCell
                    label="Total deductions"
                    value={formatCurrency(
                        payslip.total_employee_deductions_centavos,
                    )}
                />
                <TotalsCell
                    label="Net pay"
                    value={formatCurrency(payslip.net_pay_centavos)}
                    emphasis
                />
            </div>
        </div>
    );
}

function BucketCard({
    title,
    lines,
}: {
    title: string;
    lines: PayslipAuditLine[];
}) {
    const total = lines.reduce((sum, l) => sum + l.amount, 0);

    return (
        <div className="rounded-md border bg-card">
            <div className="flex items-baseline justify-between border-b px-3 py-2">
                <h4 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {title}
                </h4>
                <span className="text-sm font-semibold tabular-nums">
                    {formatCurrency(total)}
                </span>
            </div>
            {lines.length === 0 ? (
                <p className="px-3 py-3 text-xs text-muted-foreground">
                    No lines.
                </p>
            ) : (
                <ul className="divide-y">
                    {lines.map((line) => (
                        <li
                            key={`${line.code}-${line.label}`}
                            className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="truncate">{line.label}</p>
                                <p className="font-mono text-[10px] text-muted-foreground">
                                    {line.code}
                                </p>
                            </div>
                            <span className="shrink-0 tabular-nums">
                                {formatCurrency(line.amount)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function TotalsCell({
    label,
    value,
    emphasis,
}: {
    label: string;
    value: string;
    emphasis?: boolean;
}) {
    return (
        <div>
            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p
                className={
                    emphasis
                        ? 'mt-1 text-base font-semibold tabular-nums'
                        : 'mt-1 text-sm tabular-nums'
                }
            >
                {value}
            </p>
        </div>
    );
}

PayrollRunShow.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin/payroll-runs' },
        { title: 'Payroll runs', href: '/admin/payroll-runs' },
        { title: 'Show', href: '/admin/payroll-runs' },
    ],
};
