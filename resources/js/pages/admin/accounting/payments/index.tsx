import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Pencil, Plus, Trash2, Wallet, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import { Money } from '@/components/money';
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
import { DatePicker } from '@/components/ui/date-picker';
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
import {
    create as paymentCreate,
    destroy as paymentDestroy,
    edit as paymentEdit,
    index as paymentIndex,
    show as paymentShow,
} from '@/routes/admin/payments';
import type { PaymentIndexProps, PaymentRow, PaymentType } from '@/types';

const ALL = 'all';

export default function PaymentIndex({
    payments,
    filters,
    can,
}: PaymentIndexProps) {
    const [pendingDelete, setPendingDelete] = useState<PaymentRow | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const isReceipt = filters.type === 'receipt';

    /** Every navigation carries both filters, so changing one never drops the other. */
    const hasFilters =
        filters.status !== null || filters.from !== null || filters.to !== null;

    const navigate = (patch: {
        type?: PaymentType;
        status?: string | null;
        from?: string | null;
        to?: string | null;
        page?: number;
    }): void => {
        const query: Record<string, string | number> = {
            type: patch.type ?? filters.type,
        };

        const status =
            patch.status === undefined ? filters.status : patch.status;

        if (status) {
            query.status = status;
        }

        // Carried on every navigation so changing the status never silently
        // widens the date range back out again.
        const from = patch.from === undefined ? filters.from : patch.from;
        const to = patch.to === undefined ? filters.to : patch.to;

        if (from) {
            query.from = from;
        }

        if (to) {
            query.to = to;
        }

        if (patch.page) {
            query.page = patch.page;
        }

        router.get(paymentIndex().url, query, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const handleConfirmDelete = (): void => {
        if (pendingDelete === null) {
            return;
        }

        setIsDeleting(true);

        router.delete(paymentDestroy({ payment: pendingDelete.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Draft deleted.');
                setPendingDelete(null);
            },
            onError: () => {
                toast.error('Could not delete this draft.');
            },
            onFinish: () => {
                setIsDeleting(false);
            },
        });
    };

    return (
        <>
            <Head title={isReceipt ? 'Receipts' : 'Payments out'} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={isReceipt ? 'Receipts' : 'Payments out'}
                    description={
                        isReceipt
                            ? 'Money received from customers, and which invoices it settles. Anything not applied to a document is held as an advance.'
                            : 'Money paid to suppliers, and which bills it settles. Anything not applied is held as an advance.'
                    }
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link
                                    href={
                                        paymentCreate({
                                            query: { type: filters.type },
                                        }).url
                                    }
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    {isReceipt
                                        ? 'Record a receipt'
                                        : 'Record a payment'}
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <div className="flex flex-wrap items-center gap-3">
                    <Select
                        value={filters.type}
                        onValueChange={(value) =>
                            navigate({
                                type: value as PaymentType,
                                status: null,
                            })
                        }
                    >
                        <SelectTrigger
                            className="w-[12rem]"
                            aria-label="Filter by direction"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="receipt">
                                Money in (receipts)
                            </SelectItem>
                            <SelectItem value="disbursement">
                                Money out (payments)
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.status ?? ALL}
                        onValueChange={(value) =>
                            navigate({ status: value === ALL ? null : value })
                        }
                    >
                        <SelectTrigger
                            className="w-[11rem]"
                            aria-label="Filter by status"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All statuses</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="posted">Posted</SelectItem>
                            <SelectItem value="voided">Voided</SelectItem>
                        </SelectContent>
                    </Select>

                    {/*
                      Bounds are inclusive at both ends, and either can stand
                      alone — "everything since March" is as common a question
                      as a closed range, so neither picker requires the other.
                    */}
                    <div className="flex items-center gap-2">
                        <DatePicker
                            id="filter-from"
                            value={filters.from ?? ''}
                            onChange={(value) =>
                                navigate({ from: value === '' ? null : value })
                            }
                            placeholder="From"
                            className="w-[10.5rem]"
                        />
                        <span className="text-xs text-muted-foreground">
                            to
                        </span>
                        <DatePicker
                            id="filter-to"
                            value={filters.to ?? ''}
                            onChange={(value) =>
                                navigate({ to: value === '' ? null : value })
                            }
                            placeholder="To"
                            className="w-[10.5rem]"
                        />
                    </div>

                    {/*
                      Only when something is actually filtered. A permanent
                      Clear on an unfiltered list is a control that does
                      nothing, and it reads as though a filter is on.
                      `type` is deliberately not reset — it selects which list
                      you are looking at, not how it is narrowed.
                    */}
                    {hasFilters && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground"
                            onClick={() =>
                                navigate({
                                    status: null,
                                    from: null,
                                    to: null,
                                })
                            }
                        >
                            <X className="mr-1 h-3.5 w-3.5" />
                            Clear filters
                        </Button>
                    )}

                    <span className="text-xs text-muted-foreground tabular-nums">
                        {payments.total}{' '}
                        {payments.total === 1 ? 'payment' : 'payments'}
                    </span>
                </div>

                {payments.data.length === 0 ? (
                    <EmptyState
                        icon={Wallet}
                        title={
                            isReceipt
                                ? 'No receipts yet'
                                : 'No payments out yet'
                        }
                        description={
                            isReceipt
                                ? 'Record money as it comes in, and apply it to the invoices it settles.'
                                : 'Record money as it goes out, and apply it to the bills it settles.'
                        }
                        action={
                            can.create ? (
                                <Button asChild size="sm">
                                    <Link
                                        href={
                                            paymentCreate({
                                                query: { type: filters.type },
                                            }).url
                                        }
                                    >
                                        <Plus className="mr-1 h-4 w-4" />
                                        {isReceipt
                                            ? 'Record a receipt'
                                            : 'Record a payment'}
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
                                                Ref
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                {isReceipt ? 'From' : 'To'}
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Date
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Account
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Amount
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Unapplied
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
                                        {payments.data.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {row.reference ??
                                                        `#${row.id}`}
                                                </TableCell>
                                                <TableCell className="max-w-[16rem]">
                                                    <span className="line-clamp-1">
                                                        {row.contact_name ??
                                                            '—'}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {row.payment_date}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {row.cash_account_name ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Money
                                                        amount={
                                                            row.amount_centavos /
                                                            100
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Money
                                                        amount={
                                                            row.unallocated_centavos /
                                                            100
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <PaymentStatusBadge
                                                        status={row.status}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <RowActions
                                                        row={row}
                                                        onRequestDelete={
                                                            setPendingDelete
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

                {payments.last_page > 1 ? (
                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={payments.current_page === 1}
                            onClick={() =>
                                navigate({ page: payments.current_page - 1 })
                            }
                        >
                            Previous
                        </Button>
                        <span className="tabular-nums">
                            Page {payments.current_page} of {payments.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={
                                payments.current_page === payments.last_page
                            }
                            onClick={() =>
                                navigate({ page: payments.current_page + 1 })
                            }
                        >
                            Next
                        </Button>
                    </div>
                ) : null}
            </div>

            <AlertDialog
                open={pendingDelete !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingDelete(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete this draft?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This draft has never reached the ledger and has
                            settled nothing, so it can be removed outright. A
                            posted payment cannot be deleted — it is undone by
                            voiding, which reverses its entry and puts the
                            documents back.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            disabled={isDeleting}
                            onClick={(event) => {
                                event.preventDefault();
                                handleConfirmDelete();
                            }}
                        >
                            {isDeleting ? 'Deleting…' : 'Delete draft'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

/**
 * Per-row controls.
 *
 * Edit and Delete appear only on drafts — the server refuses both once
 * posted. Post and Void live on the detail page: posting moves real money and
 * settles documents, voiding reverses it, and neither belongs one click away
 * from Delete in a list.
 */
function RowActions({
    row,
    onRequestDelete,
}: {
    row: PaymentRow;
    onRequestDelete: (row: PaymentRow) => void;
}) {
    const label = row.reference ?? `payment #${row.id}`;

    return (
        <div className="flex justify-end gap-1">
            {row.can.update ? (
                <Button
                    asChild
                    size="icon"
                    variant="ghost"
                    className="h-7 w-7"
                    aria-label={`Edit ${label}`}
                >
                    <Link href={paymentEdit({ payment: row.id }).url}>
                        <Pencil className="h-4 w-4" />
                    </Link>
                </Button>
            ) : null}

            {row.can.delete ? (
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                    aria-label={`Delete ${label}`}
                    onClick={() => onRequestDelete(row)}
                >
                    <Trash2 className="h-4 w-4" />
                </Button>
            ) : null}

            <Button
                asChild
                size="icon"
                variant="ghost"
                className="h-7 w-7"
                aria-label={`Open ${label}`}
            >
                <Link href={paymentShow({ payment: row.id }).url}>
                    <ChevronRight className="h-4 w-4" />
                </Link>
            </Button>
        </div>
    );
}

export function PaymentStatusBadge({
    status,
}: {
    status: PaymentRow['status'];
}) {
    if (status === 'posted') {
        return (
            <Badge className="bg-success/15 text-success hover:bg-success/15">
                Posted
            </Badge>
        );
    }

    if (status === 'voided') {
        return <Badge variant="secondary">Voided</Badge>;
    }

    return <Badge variant="outline">Draft</Badge>;
}

PaymentIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Payments', href: paymentIndex().url },
    ],
};
