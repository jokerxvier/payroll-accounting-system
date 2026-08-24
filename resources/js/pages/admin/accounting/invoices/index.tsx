import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, FileText, Pencil, Plus, Trash2 } from 'lucide-react';
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
    create as invoiceCreate,
    destroy as invoiceDestroy,
    edit as invoiceEdit,
    index as invoiceIndex,
    show as invoiceShow,
} from '@/routes/admin/invoices';
import type { InvoiceIndexProps, InvoiceRow, InvoiceType } from '@/types';

const ALL = 'all';

export default function InvoiceIndex({
    invoices,
    filters,
    can,
}: InvoiceIndexProps) {
    const [pendingDelete, setPendingDelete] = useState<InvoiceRow | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const isSales = filters.type === 'sales';

    /**
     * Every navigation carries the active type and status. Passing only the
     * changed key would silently drop the other and drop the operator into a
     * list they did not ask for.
     */
    const navigate = (patch: {
        type?: InvoiceType;
        status?: string | null;
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

        if (patch.page) {
            query.page = patch.page;
        }

        router.get(invoiceIndex().url, query, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const handleConfirmDelete = (): void => {
        if (pendingDelete === null) {
            return;
        }

        setIsDeleting(true);

        router.delete(invoiceDestroy({ invoice: pendingDelete.id }).url, {
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
            <Head title={isSales ? 'Invoices' : 'Bills'} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={isSales ? 'Invoices' : 'Bills'}
                    description={
                        isSales
                            ? 'Sales invoices issued to customers. A draft carries no number; approving it takes the next serial and posts it to the ledger.'
                            : 'Bills received from suppliers. Approving one posts the payable and the input VAT.'
                    }
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link
                                    href={
                                        invoiceCreate({
                                            query: { type: filters.type },
                                        }).url
                                    }
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    {isSales ? 'New invoice' : 'New bill'}
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
                                type: value as InvoiceType,
                                status: null,
                            })
                        }
                    >
                        <SelectTrigger
                            className="w-[11rem]"
                            aria-label="Filter by document type"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="sales">Invoices</SelectItem>
                            <SelectItem value="purchase">Bills</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.status ?? ALL}
                        onValueChange={(value) =>
                            navigate({ status: value === ALL ? null : value })
                        }
                    >
                        <SelectTrigger
                            className="w-[12rem]"
                            aria-label="Filter by status"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All statuses</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="approved">Approved</SelectItem>
                            <SelectItem value="partially_paid">
                                Partially paid
                            </SelectItem>
                            <SelectItem value="paid">Paid</SelectItem>
                            <SelectItem value="voided">Voided</SelectItem>
                        </SelectContent>
                    </Select>

                    <span className="text-xs text-muted-foreground tabular-nums">
                        {invoices.total}{' '}
                        {invoices.total === 1 ? 'document' : 'documents'}
                    </span>
                </div>

                {invoices.data.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title={
                            isSales
                                ? 'No invoices yet'
                                : 'No bills recorded yet'
                        }
                        description={
                            isSales
                                ? 'Raise the first invoice against a contact. It stays a draft until you approve it.'
                                : 'Record what a supplier has billed the school, so the payable shows up in the ledger.'
                        }
                        action={
                            can.create ? (
                                <Button asChild size="sm">
                                    <Link
                                        href={
                                            invoiceCreate({
                                                query: { type: filters.type },
                                            }).url
                                        }
                                    >
                                        <Plus className="mr-1 h-4 w-4" />
                                        {isSales ? 'New invoice' : 'New bill'}
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
                                                Number
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                {isSales
                                                    ? 'Customer'
                                                    : 'Supplier'}
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Issued
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Due
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Total
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Balance
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
                                        {invoices.data.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {row.number ?? '—'}
                                                </TableCell>
                                                <TableCell className="max-w-[16rem]">
                                                    <span className="line-clamp-1">
                                                        {row.contact_name ??
                                                            '—'}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {row.issue_date}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground tabular-nums">
                                                    {row.due_date ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Money
                                                        amount={
                                                            row.total_centavos /
                                                            100
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Money
                                                        amount={
                                                            row.balance_due_centavos /
                                                            100
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <InvoiceStatusBadge
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

                {invoices.last_page > 1 ? (
                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={invoices.current_page === 1}
                            onClick={() =>
                                navigate({ page: invoices.current_page - 1 })
                            }
                        >
                            Previous
                        </Button>
                        <span className="tabular-nums">
                            Page {invoices.current_page} of {invoices.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={
                                invoices.current_page === invoices.last_page
                            }
                            onClick={() =>
                                navigate({ page: invoices.current_page + 1 })
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
                            {pendingDelete
                                ? `This draft has never been numbered or posted, so it can be removed outright. An approved document cannot be deleted — it is cancelled by voiding it, which keeps its serial on record.`
                                : ''}
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
 * Edit and Delete appear only on drafts — the server refuses both on an
 * issued document, and offering a control that 403s is worse than not
 * offering it. The chevron is always present so every row, numbered or not,
 * has one obvious way in.
 *
 * Approve and Void deliberately live on the detail page. Approving allocates
 * a BIR serial and posts to the ledger, and voiding reverses it; neither
 * belongs one click away from Delete in a list.
 */
function RowActions({
    row,
    onRequestDelete,
}: {
    row: InvoiceRow;
    onRequestDelete: (row: InvoiceRow) => void;
}) {
    const label = row.number ?? `draft #${row.id}`;

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
                    <Link href={invoiceEdit({ invoice: row.id }).url}>
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
                <Link href={invoiceShow({ invoice: row.id }).url}>
                    <ChevronRight className="h-4 w-4" />
                </Link>
            </Button>
        </div>
    );
}

export function InvoiceStatusBadge({
    status,
}: {
    status: InvoiceRow['status'];
}) {
    if (status === 'paid') {
        return (
            <Badge className="bg-success/15 text-success hover:bg-success/15">
                Paid
            </Badge>
        );
    }

    if (status === 'partially_paid') {
        return <Badge variant="outline">Partially paid</Badge>;
    }

    if (status === 'voided') {
        return <Badge variant="secondary">Voided</Badge>;
    }

    if (status === 'draft') {
        return <Badge variant="outline">Draft</Badge>;
    }

    return <Badge variant="outline">Approved</Badge>;
}

InvoiceIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Invoices', href: invoiceIndex().url },
    ],
};
