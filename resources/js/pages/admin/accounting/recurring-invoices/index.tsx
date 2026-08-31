import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    Pause,
    Pencil,
    Play,
    Plus,
    Trash2,
} from 'lucide-react';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { create as invoiceCreate } from '@/routes/admin/invoices';
import {
    destroy as scheduleDestroy,
    edit as scheduleEdit,
    index as scheduleIndex,
    pause as schedulePause,
    resume as scheduleResume,
} from '@/routes/admin/recurring-invoices';
import type { RecurringInvoiceIndexProps, RecurringInvoiceRow } from '@/types';
import { RECURRING_FREQUENCY_LABELS } from '@/types';

/**
 * The standing instructions, not the invoices they raise.
 *
 * The column that earns its place is "Next invoice": a schedule's whole job is
 * to fire on a date, and the question an operator has is always whether it is
 * about to, or whether it has quietly stopped.
 */
export default function RecurringInvoiceIndex({
    schedules,
    filters,
    can,
}: RecurringInvoiceIndexProps) {
    const [pendingDelete, setPendingDelete] =
        useState<RecurringInvoiceRow | null>(null);

    const navigate = (patch: { status?: string | null }) => {
        const status =
            patch.status === undefined ? filters.status : patch.status;

        router.get(scheduleIndex().url, status === null ? {} : { status }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const confirmDelete = () => {
        if (pendingDelete === null) {
            return;
        }

        router.delete(
            scheduleDestroy({ recurringInvoice: pendingDelete.id }).url,
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Schedule deleted.'),
                onError: () =>
                    toast.error('That schedule could not be deleted.'),
                onFinish: () => setPendingDelete(null),
            },
        );
    };

    return (
        <>
            <Head title="Recurring invoices" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Recurring invoices"
                    description="Standing instructions that raise a draft invoice on a cadence. Set one up while raising an invoice — tick Repeat on the invoice form. Drafts still need approving before anything reaches the ledger."
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link
                                    href={
                                        invoiceCreate({
                                            query: { type: 'sales' },
                                        }).url
                                    }
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    New invoice
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <div className="flex flex-wrap items-center gap-3">
                    <div className="w-[11rem] space-y-1">
                        <Select
                            value={filters.status ?? 'all'}
                            onValueChange={(value) =>
                                navigate({
                                    status: value === 'all' ? null : value,
                                })
                            }
                        >
                            <SelectTrigger id="status" className="w-full">
                                <SelectValue placeholder="All schedules" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All schedules
                                </SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="paused">Paused</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {filters.status !== null ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => navigate({ status: null })}
                        >
                            Clear filter
                        </Button>
                    ) : null}

                    <span className="text-sm text-muted-foreground">
                        {schedules.total}{' '}
                        {schedules.total === 1 ? 'schedule' : 'schedules'}
                    </span>
                </div>

                {schedules.data.length === 0 ? (
                    <EmptyState
                        icon={CalendarClock}
                        title="No recurring invoices yet"
                        description="A schedule bills the same family the same fees every month, so nobody has to type the invoice again. Raise the first invoice and tick Repeat on the form — the schedule takes over from the next month. It raises drafts; you still approve them."
                        action={
                            can.create ? (
                                <Button asChild>
                                    <Link
                                        href={
                                            invoiceCreate({
                                                query: { type: 'sales' },
                                            }).url
                                        }
                                    >
                                        <Plus className="mr-1 h-4 w-4" />
                                        New invoice
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Schedule
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Payer
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Every
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Next invoice
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Raised
                                            </TableHead>
                                            <TableHead className="sr-only">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {schedules.data.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell>
                                                    <p className="font-medium">
                                                        {row.name}
                                                    </p>
                                                    {row.last_error ? (
                                                        <p className="mt-0.5 flex items-start gap-1 text-xs text-destructive">
                                                            <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
                                                            {row.last_error}
                                                        </p>
                                                    ) : null}
                                                </TableCell>
                                                <TableCell>
                                                    <p className="text-sm">
                                                        {row.contact_name ??
                                                            '—'}
                                                    </p>
                                                    {row.student_name ? (
                                                        <p className="text-xs text-muted-foreground">
                                                            for{' '}
                                                            {row.student_name}
                                                        </p>
                                                    ) : null}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {
                                                        RECURRING_FREQUENCY_LABELS[
                                                            row.frequency
                                                        ]
                                                    }
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        · day {row.day_of_month}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {row.is_active ? (
                                                        <span className="font-mono text-sm">
                                                            {row.next_run_on}
                                                        </span>
                                                    ) : (
                                                        <Badge variant="secondary">
                                                            Paused
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {row.generated_count}
                                                </TableCell>
                                                <TableCell>
                                                    <RowActions
                                                        row={row}
                                                        onDelete={() =>
                                                            setPendingDelete(
                                                                row,
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {schedules.last_page > 1 ? (
                    <div className="flex items-center justify-between">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={schedules.current_page === 1}
                            onClick={() =>
                                router.get(
                                    schedules.prev_page_url ??
                                        scheduleIndex().url,
                                )
                            }
                        >
                            Previous
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            Page {schedules.current_page} of{' '}
                            {schedules.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={
                                schedules.current_page === schedules.last_page
                            }
                            onClick={() =>
                                router.get(
                                    schedules.next_page_url ??
                                        scheduleIndex().url,
                                )
                            }
                        >
                            Next
                        </Button>
                    </div>
                ) : null}
            </div>

            <AlertDialog
                open={pendingDelete !== null}
                onOpenChange={(open) => !open && setPendingDelete(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete {pendingDelete?.name}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The invoices it has already raised are not affected.
                            But deleting it also forgets which periods it has
                            billed, so a new schedule covering the same months
                            could bill them again. To stop it billing, pause it
                            instead.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep it</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete}>
                            Delete schedule
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

function RowActions({
    row,
    onDelete,
}: {
    row: RecurringInvoiceRow;
    onDelete: () => void;
}) {
    const toggle = () => {
        const target = row.is_active
            ? schedulePause({ recurringInvoice: row.id }).url
            : scheduleResume({ recurringInvoice: row.id }).url;

        router.post(target, {}, { preserveScroll: true });
    };

    return (
        <div className="flex items-center justify-end gap-1">
            {row.can.pause ? (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={toggle}
                    aria-label={
                        row.is_active ? 'Pause schedule' : 'Resume schedule'
                    }
                >
                    {row.is_active ? (
                        <Pause className="h-4 w-4" />
                    ) : (
                        <Play className="h-4 w-4" />
                    )}
                </Button>
            ) : null}
            {row.can.update ? (
                <Button variant="ghost" size="sm" asChild>
                    <Link
                        href={scheduleEdit({ recurringInvoice: row.id }).url}
                        aria-label="Edit schedule"
                    >
                        <Pencil className="h-4 w-4" />
                    </Link>
                </Button>
            ) : null}
            {row.can.delete ? (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={onDelete}
                    aria-label="Delete schedule"
                >
                    <Trash2 className="h-4 w-4" />
                </Button>
            ) : null}
        </div>
    );
}

RecurringInvoiceIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Recurring invoices', href: scheduleIndex().url },
    ],
};
