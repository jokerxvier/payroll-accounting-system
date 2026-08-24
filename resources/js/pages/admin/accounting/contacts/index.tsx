import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Users } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ContactEditSheet } from '@/components/admin/contact-edit-sheet';
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
import { Input } from '@/components/ui/input';
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
import { useTableFilters } from '@/hooks/use-table-filters';
import {
    destroy as contactsDestroy,
    index as contactsIndex,
} from '@/routes/admin/contacts';
import type { ContactIndexProps, ContactRow } from '@/types';

const ALL = 'all';

export default function ContactsIndex({
    contacts,
    filters,
    receivableAccountOptions,
    payableAccountOptions,
    can,
}: ContactIndexProps) {
    const [sheetOpen, setSheetOpen] = useState(false);
    const [editing, setEditing] = useState<ContactRow | undefined>(undefined);
    const [pendingDelete, setPendingDelete] = useState<ContactRow | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    // URL-synced filters with a debounced text search — the hook exists for
    // exactly this, so the page does not hand-roll another one.
    const {
        filters: current,
        apply,
        applyDebounced,
    } = useTableFilters(
        { search: filters.search ?? '', role: filters.role ?? '' },
        contactsIndex().url,
    );

    const openCreate = (): void => {
        setEditing(undefined);
        setSheetOpen(true);
    };

    const openEdit = (row: ContactRow): void => {
        setEditing(row);
        setSheetOpen(true);
    };

    /** Paging has to carry the active filters, or it drops you into an unfiltered list. */
    const goPage = (page: number): void => {
        const query: Record<string, string | number> = { page };

        if (current.search) {
            query.search = current.search;
        }

        if (current.role) {
            query.role = current.role;
        }

        router.get(contactsIndex().url, query, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const handleConfirmDelete = (): void => {
        if (pendingDelete === null) {
            return;
        }

        setIsDeleting(true);

        router.delete(contactsDestroy({ contact: pendingDelete.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Deleted ${pendingDelete.name}.`);
                setPendingDelete(null);
            },
            onError: () => {
                toast.error(
                    'Could not delete this contact. Documents may reference it — mark it inactive instead.',
                );
            },
            onFinish: () => {
                setIsDeleting(false);
            },
        });
    };

    const isFiltered = Boolean(current.search || current.role);

    return (
        <>
            <Head title="Contacts" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Contacts"
                    description="Who an invoice or bill is addressed to. A contact can be a customer, a supplier, or both."
                    actions={
                        can.create ? (
                            <Button type="button" onClick={openCreate}>
                                <Plus className="mr-1 h-4 w-4" />
                                New contact
                            </Button>
                        ) : undefined
                    }
                />

                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        aria-label="Search contacts"
                        placeholder="Search by name, code, or TIN…"
                        className="max-w-xs"
                        value={current.search}
                        onChange={(e) =>
                            applyDebounced({ search: e.target.value })
                        }
                    />
                    <Select
                        value={current.role === '' ? ALL : current.role}
                        onValueChange={(value) =>
                            apply({ role: value === ALL ? '' : value })
                        }
                    >
                        <SelectTrigger
                            className="w-[11rem]"
                            aria-label="Filter by role"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All contacts</SelectItem>
                            <SelectItem value="customer">Customers</SelectItem>
                            <SelectItem value="supplier">Suppliers</SelectItem>
                        </SelectContent>
                    </Select>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {contacts.total}{' '}
                        {contacts.total === 1 ? 'contact' : 'contacts'}
                    </span>
                </div>

                {contacts.data.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={
                            isFiltered
                                ? 'No contacts match that search'
                                : 'No contacts yet'
                        }
                        description={
                            isFiltered
                                ? 'Try a different name, code, or TIN.'
                                : 'Add the people and businesses this school invoices or buys from.'
                        }
                        action={
                            can.create && !isFiltered ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={openCreate}
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    New contact
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
                                                Role
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                TIN
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Contact
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
                                        {contacts.data.map((row) => (
                                            <TableRow
                                                key={row.id}
                                                className={
                                                    row.is_active
                                                        ? undefined
                                                        : 'opacity-60'
                                                }
                                            >
                                                <TableCell className="font-mono text-xs">
                                                    {row.code}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {row.name}
                                                </TableCell>
                                                <TableCell>
                                                    <RoleBadges row={row} />
                                                </TableCell>
                                                <TableCell className="font-mono text-xs text-muted-foreground">
                                                    {row.tin ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground">
                                                    {row.email ??
                                                        row.phone ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell>
                                                    {row.is_active ? (
                                                        <Badge className="bg-success/15 text-success hover:bg-success/15">
                                                            Active
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="secondary">
                                                            Inactive
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        {row.can.update ? (
                                                            <Button
                                                                type="button"
                                                                size="icon"
                                                                variant="ghost"
                                                                className="h-7 w-7"
                                                                aria-label={`Edit contact ${row.code}`}
                                                                onClick={() =>
                                                                    openEdit(
                                                                        row,
                                                                    )
                                                                }
                                                            >
                                                                <Pencil className="h-4 w-4" />
                                                            </Button>
                                                        ) : null}
                                                        {row.can.delete ? (
                                                            <Button
                                                                type="button"
                                                                size="icon"
                                                                variant="ghost"
                                                                className="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                                aria-label={`Delete contact ${row.code}`}
                                                                onClick={() =>
                                                                    setPendingDelete(
                                                                        row,
                                                                    )
                                                                }
                                                            >
                                                                <Trash2 className="h-4 w-4" />
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

                {contacts.last_page > 1 ? (
                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={contacts.current_page === 1}
                            onClick={() => goPage(contacts.current_page - 1)}
                        >
                            Previous
                        </Button>
                        <span className="tabular-nums">
                            Page {contacts.current_page} of {contacts.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={
                                contacts.current_page === contacts.last_page
                            }
                            onClick={() => goPage(contacts.current_page + 1)}
                        >
                            Next
                        </Button>
                    </div>
                ) : null}
            </div>

            <ContactEditSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                contact={editing}
                receivableAccountOptions={receivableAccountOptions}
                payableAccountOptions={payableAccountOptions}
            />

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
                        <AlertDialogTitle>
                            Delete this contact?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {pendingDelete
                                ? `${pendingDelete.name} will be removed. If any document already names them, the deletion is blocked and you'll be asked to mark them inactive instead, so that history stays readable.`
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
                            {isDeleting ? 'Deleting…' : 'Delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

function RoleBadges({ row }: { row: ContactRow }) {
    return (
        <div className="flex flex-wrap gap-1">
            {row.is_customer ? <Badge variant="outline">Customer</Badge> : null}
            {row.is_supplier ? <Badge variant="outline">Supplier</Badge> : null}
        </div>
    );
}

ContactsIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Contacts', href: contactsIndex().url },
    ],
};
