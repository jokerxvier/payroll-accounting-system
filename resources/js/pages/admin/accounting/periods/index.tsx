import { Head, Link, router } from '@inertiajs/react';
import { CalendarRange, Lock, LockOpen, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
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
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    close as periodsClose,
    create as periodsCreate,
    edit as periodsEdit,
    index as periodsIndex,
    reopen as periodsReopen,
} from '@/routes/admin/accounting-periods';
import type { AccountingPeriodRow } from '@/types';

interface Props {
    periods: AccountingPeriodRow[];
    can: { create: boolean };
}

/** Which transition a confirmation dialog is currently asking about. */
type PendingTransition = {
    period: AccountingPeriodRow;
    action: 'close' | 'reopen';
} | null;

function formatDate(iso: string): string {
    return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function AccountingPeriodsIndex({ periods, can }: Props) {
    const [pending, setPending] = useState<PendingTransition>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const handleConfirm = (): void => {
        if (pending === null) {
            return;
        }

        const { period, action } = pending;
        const url =
            action === 'close'
                ? periodsClose({ accountingPeriod: period.id }).url
                : periodsReopen({ accountingPeriod: period.id }).url;

        setIsSubmitting(true);

        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(
                        action === 'close'
                            ? `Period ${period.code} closed.`
                            : `Period ${period.code} reopened.`,
                    );
                    setPending(null);
                },
                onError: () => {
                    toast.error(
                        action === 'close'
                            ? 'Could not close this period.'
                            : 'Could not reopen this period.',
                    );
                },
                onFinish: () => {
                    setIsSubmitting(false);
                },
            },
        );
    };

    return (
        <>
            <Head title="Accounting periods" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Accounting periods"
                    description="The windows entries are posted into. Closing a period freezes it: corrections are then made by posting a reversing entry into an open period."
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link href={periodsCreate().url}>
                                    <Plus className="mr-1 h-4 w-4" />
                                    New period
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {periods.length === 0 ? (
                    <EmptyState
                        icon={CalendarRange}
                        title="No accounting periods yet"
                        description="Create the first period before posting anything to the ledger. Periods may not overlap."
                        action={
                            can.create ? (
                                <Button asChild size="sm">
                                    <Link href={periodsCreate().url}>
                                        <Plus className="mr-1 h-4 w-4" />
                                        New period
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <Table className="text-sm">
                                    <TableHeader>
                                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Code
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Name
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Range
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Fiscal year
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Status
                                            </TableHead>
                                            <TableHead className="sr-only text-right">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {periods.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {row.code}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {row.name ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground tabular-nums">
                                                    {formatDate(row.start_date)}{' '}
                                                    – {formatDate(row.end_date)}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {row.fiscal_year ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <PeriodStatusBadge
                                                        row={row}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        {row.can.update ? (
                                                            <Button
                                                                asChild
                                                                size="sm"
                                                                variant="ghost"
                                                                aria-label={`Edit period ${row.code}`}
                                                            >
                                                                <Link
                                                                    href={
                                                                        periodsEdit(
                                                                            {
                                                                                accountingPeriod:
                                                                                    row.id,
                                                                            },
                                                                        ).url
                                                                    }
                                                                >
                                                                    <Pencil className="h-4 w-4" />
                                                                </Link>
                                                            </Button>
                                                        ) : null}

                                                        {row.can.close ? (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setPending({
                                                                        period: row,
                                                                        action: 'close',
                                                                    })
                                                                }
                                                            >
                                                                <Lock className="mr-1 h-3.5 w-3.5" />
                                                                Close
                                                            </Button>
                                                        ) : null}

                                                        {row.can.reopen ? (
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    setPending({
                                                                        period: row,
                                                                        action: 'reopen',
                                                                    })
                                                                }
                                                            >
                                                                <LockOpen className="mr-1 h-3.5 w-3.5" />
                                                                Reopen
                                                            </Button>
                                                        ) : null}
                                                    </div>
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

            <AlertDialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPending(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {pending?.action === 'close'
                                ? 'Close this period?'
                                : 'Reopen this period?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {pending === null
                                ? ''
                                : pending.action === 'close'
                                  ? `No further entries can be posted into ${pending.period.code} once it is closed. Corrections will have to be posted as reversing entries in an open period.`
                                  : `Reopening ${pending.period.code} allows entries to be posted into a period that may already have been reported. This is recorded in the audit log against your name.`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isSubmitting}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={(event) => {
                                event.preventDefault();
                                handleConfirm();
                            }}
                            disabled={isSubmitting}
                        >
                            {isSubmitting
                                ? 'Working…'
                                : pending?.action === 'close'
                                  ? 'Close period'
                                  : 'Reopen period'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

function PeriodStatusBadge({ row }: { row: AccountingPeriodRow }) {
    if (row.status === 'open') {
        return (
            <div className="flex items-center gap-2">
                <Badge className="bg-success/15 text-success hover:bg-success/15">
                    Open
                </Badge>
                {row.reopened_at !== null ? (
                    <span className="text-xs text-muted-foreground">
                        reopened
                    </span>
                ) : null}
            </div>
        );
    }

    return <Badge variant="secondary">Closed</Badge>;
}

AccountingPeriodsIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Periods', href: periodsIndex().url },
    ],
};
