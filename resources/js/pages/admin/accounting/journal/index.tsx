import { Head, Link, router } from '@inertiajs/react';
import { BookText, Plus } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { Money } from '@/components/money';
import { PageHeader } from '@/components/page-header';
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
    create as journalCreate,
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
    const onStatusChange = (value: string): void => {
        router.get(journalIndex().url, value === ALL ? {} : { status: value }, {
            preserveScroll: true,
            preserveState: true,
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
                    <Select
                        value={filters.status ?? ALL}
                        onValueChange={onStatusChange}
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
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {entries.data.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell className="font-mono text-xs">
                                                    <Link
                                                        href={
                                                            journalShow({
                                                                journalEntry:
                                                                    row.id,
                                                            }).url
                                                        }
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        {row.entry_number ??
                                                            '—'}
                                                    </Link>
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
