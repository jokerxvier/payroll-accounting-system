import { Head, Link, router } from '@inertiajs/react';
import {
    BookText,
    ChevronRight,
    Pencil,
    Plus,
    Search,
    Trash2,
    X,
} from 'lucide-react';
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
    create as journalCreate,
    destroy as journalDestroy,
    edit as journalEdit,
    index as journalIndex,
    show as journalShow,
} from '@/routes/admin/journal-entries';
import type { JournalEntryIndexProps, JournalEntryRow } from '@/types';

const ALL = 'all';

export default function JournalIndex({
    entries,
    filters,
    can,
}: JournalEntryIndexProps) {
    const [pendingDelete, setPendingDelete] = useState<JournalEntryRow | null>(
        null,
    );
    const [isDeleting, setIsDeleting] = useState(false);

    /*
     * The shared filter hook, not another hand-rolled navigate().
     *
     * The previous local function existed because four filters have to travel
     * together or they drop each other — which is exactly what this hook does,
     * plus the debounce a text search needs.
     */
    const {
        filters: current,
        apply,
        applyDebounced,
    } = useTableFilters(
        {
            search: filters.search ?? '',
            status: filters.status ?? '',
            from: filters.from ?? '',
            to: filters.to ?? '',
        },
        journalIndex().url,
    );

    const hasFilters =
        current.search !== '' ||
        current.status !== '' ||
        current.from !== '' ||
        current.to !== '';

    /** Paging carries the filters but is never merged into them. */
    const goPage = (page: number): void => {
        const query: Record<string, string | number> = { page };

        for (const key of ['search', 'status', 'from', 'to'] as const) {
            if (current[key]) {
                query[key] = current[key];
            }
        }

        router.get(journalIndex().url, query, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const handleConfirmDelete = (): void => {
        if (pendingDelete === null) {
            return;
        }

        setIsDeleting(true);

        router.delete(journalDestroy({ journalEntry: pendingDelete.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Draft journal entry deleted.');
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
            <Head title="Journal" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Journal"
                    description="Every transaction posted to the ledger. A posted entry is never edited — corrections are posted as a reversing entry, and both stay on the books."
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link href={journalCreate().url}>
                                    <Plus className="mr-1 h-4 w-4" />
                                    New entry
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <div className="flex flex-wrap items-center gap-3">
                    {/*
                      First in the row because it is the widest net. The others
                      narrow a list; this one finds a thing — an entry number,
                      a document reference, or a payer, since every posting
                      service writes the counterparty into the narration.
                    */}
                    <div className="relative w-[18rem]">
                        <Search className="absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            type="search"
                            aria-label="Search journal entries"
                            placeholder="Entry number, reference or name…"
                            defaultValue={current.search}
                            className="pl-8"
                            onChange={(e) =>
                                applyDebounced({ search: e.target.value })
                            }
                        />
                    </div>

                    <Select
                        value={current.status === '' ? ALL : current.status}
                        onValueChange={(value) =>
                            apply({ status: value === ALL ? '' : value })
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
                            value={current.from}
                            onChange={(value) => apply({ from: value })}
                            placeholder="From"
                            className="w-[10.5rem]"
                        />
                        <span className="text-xs text-muted-foreground">
                            to
                        </span>
                        <DatePicker
                            id="filter-to"
                            value={current.to}
                            onChange={(value) => apply({ to: value })}
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
                                apply({
                                    search: '',
                                    status: '',
                                    from: '',
                                    to: '',
                                })
                            }
                        >
                            <X className="mr-1 h-3.5 w-3.5" />
                            Clear filters
                        </Button>
                    )}

                    <span className="text-xs text-muted-foreground tabular-nums">
                        {entries.total}{' '}
                        {entries.total === 1 ? 'entry' : 'entries'}
                    </span>
                </div>

                {entries.data.length === 0 ? (
                    <EmptyState
                        icon={BookText}
                        title="No journal entries yet"
                        description="Post the first entry, or let a payroll run post one for you once the ledger seam lands."
                        action={
                            can.create ? (
                                <Button asChild size="sm">
                                    <Link href={journalCreate().url}>
                                        <Plus className="mr-1 h-4 w-4" />
                                        New entry
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
                                                Date
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Narration
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Period
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Amount
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
                                        {entries.data.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {row.entry_number ?? '—'}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {row.date}
                                                </TableCell>
                                                <TableCell className="max-w-[24rem]">
                                                    <span className="line-clamp-1">
                                                        {row.narration ?? '—'}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="font-mono text-xs text-muted-foreground">
                                                    {row.period_code ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Money
                                                        amount={
                                                            row.total_debit_centavos /
                                                            100
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <EntryStatusBadge
                                                        row={row}
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

                {entries.last_page > 1 ? (
                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={entries.current_page === 1}
                            onClick={() => goPage(entries.current_page - 1)}
                        >
                            Previous
                        </Button>
                        <span className="tabular-nums">
                            Page {entries.current_page} of {entries.last_page}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={
                                entries.current_page === entries.last_page
                            }
                            onClick={() => goPage(entries.current_page + 1)}
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
                                ? `"${pendingDelete.narration ?? 'Untitled entry'}" has never reached the ledger, so it can be removed outright. Posted entries cannot be deleted — they are corrected by posting a reversal.`
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
 * Edit and Delete appear only on drafts — the server refuses both on a posted
 * entry, and offering a control that 403s is worse than not offering it. The
 * chevron is always present so every row, numbered or not, has one obvious
 * way in.
 *
 * Post and Reverse deliberately live on the detail page instead. Posting is
 * irreversible — it is corrected only by posting a further entry — and a
 * one-click irreversible action sitting beside Delete in a list is a misclick
 * waiting to happen.
 */
function RowActions({
    row,
    onRequestDelete,
}: {
    row: JournalEntryRow;
    onRequestDelete: (row: JournalEntryRow) => void;
}) {
    // A draft has no entry number, so labels fall back to the id rather than
    // naming the control "Edit journal entry —".
    const label = row.entry_number ?? `#${row.id}`;

    return (
        <div className="flex justify-end gap-1">
            {row.can.update ? (
                <Button
                    asChild
                    size="icon"
                    variant="ghost"
                    className="h-7 w-7"
                    aria-label={`Edit journal entry ${label}`}
                >
                    <Link href={journalEdit({ journalEntry: row.id }).url}>
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
                    aria-label={`Delete journal entry ${label}`}
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
                aria-label={`Open journal entry ${label}`}
            >
                <Link href={journalShow({ journalEntry: row.id }).url}>
                    <ChevronRight className="h-4 w-4" />
                </Link>
            </Button>
        </div>
    );
}

/**
 * A reversed entry still reads as posted, because it is — it and its
 * reversal both sit on the books and offset each other. The extra marker
 * says a correction exists without implying the original was removed.
 */
function EntryStatusBadge({ row }: { row: JournalEntryRow }) {
    if (row.status === 'posted') {
        return (
            <div className="flex flex-wrap items-center gap-1.5">
                <Badge className="bg-success/15 text-success hover:bg-success/15">
                    Posted
                </Badge>
                {row.has_been_reversed ? (
                    <Badge variant="outline">Reversed</Badge>
                ) : null}
                {row.is_reversal ? (
                    <Badge variant="outline">Reversal</Badge>
                ) : null}
            </div>
        );
    }

    if (row.status === 'voided') {
        return <Badge variant="secondary">Voided</Badge>;
    }

    return <Badge variant="outline">Draft</Badge>;
}

JournalIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Journal', href: journalIndex().url },
    ],
};
