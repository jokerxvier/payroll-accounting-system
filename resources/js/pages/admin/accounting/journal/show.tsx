import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Lock, Pencil, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
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
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    destroy as journalDestroy,
    edit as journalEdit,
    index as journalIndex,
    post as journalPost,
    reverse as journalReverse,
    show as journalShow,
} from '@/routes/admin/journal-entries';
import type { JournalEntryDetail } from '@/types';

interface Props {
    entry: JournalEntryDetail;
}

type Pending = 'post' | 'reverse' | 'delete' | null;

export default function JournalShow({ entry }: Props) {
    const [pending, setPending] = useState<Pending>(null);
    const [busy, setBusy] = useState(false);

    const run = (action: Exclude<Pending, null>): void => {
        setBusy(true);

        const done = {
            onFinish: () => {
                setBusy(false);
                setPending(null);
            },
        };

        if (action === 'delete') {
            router.delete(journalDestroy({ journalEntry: entry.id }).url, {
                ...done,
                onSuccess: () => toast.success('Draft deleted.'),
            });

            return;
        }

        const url =
            action === 'post'
                ? journalPost({ journalEntry: entry.id }).url
                : journalReverse({ journalEntry: entry.id }).url;

        router.post(url, {}, done);
    };

    return (
        <>
            <Head title={entry.entry_number ?? 'Draft journal entry'} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={entry.entry_number ?? 'Draft entry'}
                    description={entry.narration ?? 'No narration recorded.'}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild variant="outline" size="sm">
                                <Link href={journalIndex().url}>
                                    <ArrowLeft className="mr-1 h-4 w-4" />
                                    Journal
                                </Link>
                            </Button>

                            {entry.can.update ? (
                                <Button asChild variant="outline" size="sm">
                                    <Link
                                        href={
                                            journalEdit({
                                                journalEntry: entry.id,
                                            }).url
                                        }
                                    >
                                        <Pencil className="mr-1 h-4 w-4" />
                                        Edit
                                    </Link>
                                </Button>
                            ) : null}

                            {entry.can.delete ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    onClick={() => setPending('delete')}
                                >
                                    <Trash2 className="mr-1 h-4 w-4" />
                                    Delete
                                </Button>
                            ) : null}

                            {entry.can.post ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() => setPending('post')}
                                >
                                    <Lock className="mr-1 h-4 w-4" />
                                    Post to ledger
                                </Button>
                            ) : null}

                            {entry.can.reverse ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setPending('reverse')}
                                >
                                    <RotateCcw className="mr-1 h-4 w-4" />
                                    Reverse
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <EntryFacts entry={entry} />

                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            {/* Two-column money layout per THEME.md §6.3 — one
                                side populated per row, account codes in mono. */}
                            <Table className="text-sm">
                                <TableHeader>
                                    <TableRow className="bg-muted/40 hover:bg-muted/40">
                                        <TableHead className="w-[3rem] text-xs tracking-wide text-muted-foreground uppercase">
                                            #
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Account
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Memo
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Debit
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Credit
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {entry.lines.map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell className="font-mono text-xs text-muted-foreground">
                                                {line.line_number}
                                            </TableCell>
                                            <TableCell>
                                                <span className="font-mono text-xs">
                                                    {line.account_code}
                                                </span>{' '}
                                                {line.account_name}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {line.description ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {line.debit_centavos === 0 ? (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : (
                                                    <Money
                                                        amount={
                                                            line.debit_centavos /
                                                            100
                                                        }
                                                    />
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {line.credit_centavos === 0 ? (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : (
                                                    <Money
                                                        amount={
                                                            line.credit_centavos /
                                                            100
                                                        }
                                                    />
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell
                                            colSpan={3}
                                            className="font-medium"
                                        >
                                            Total
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            <Money
                                                amount={
                                                    entry.total_debit_centavos /
                                                    100
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            <Money
                                                amount={
                                                    entry.total_credit_centavos /
                                                    100
                                                }
                                            />
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
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
                            {pending === 'post'
                                ? 'Post this entry to the ledger?'
                                : pending === 'reverse'
                                  ? 'Post a reversing entry?'
                                  : 'Delete this draft?'}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {pending === 'post'
                                ? 'Posting commits these figures to the books and assigns an entry number. After that the entry cannot be edited — corrections are made by posting a reversal.'
                                : pending === 'reverse'
                                  ? 'This posts a mirror image of the entry. The original stays on the books and the two offset each other, so the correction is visible rather than hidden.'
                                  : 'This draft has never reached the ledger, so it can be removed outright.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={busy}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant={
                                pending === 'delete' ? 'destructive' : 'default'
                            }
                            disabled={busy}
                            onClick={(event) => {
                                event.preventDefault();

                                if (pending !== null) {
                                    run(pending);
                                }
                            }}
                        >
                            {busy
                                ? 'Working…'
                                : pending === 'post'
                                  ? 'Post entry'
                                  : pending === 'reverse'
                                    ? 'Post reversal'
                                    : 'Delete draft'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

function EntryFacts({ entry }: { entry: JournalEntryDetail }) {
    return (
        <Card>
            <CardContent className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <Fact label="Date" value={entry.date} mono />
                <Fact
                    label="Period"
                    value={entry.period_code ?? 'Not yet assigned'}
                    mono
                />
                <Fact label="Reference" value={entry.reference ?? '—'} mono />
                <div className="space-y-1">
                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                        Status
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5">
                        {entry.status === 'posted' ? (
                            <Badge className="bg-success/15 text-success hover:bg-success/15">
                                Posted
                            </Badge>
                        ) : (
                            <Badge variant="outline" className="capitalize">
                                {entry.status}
                            </Badge>
                        )}
                        {entry.has_been_reversed ? (
                            <Badge variant="outline">Reversed</Badge>
                        ) : null}
                        {entry.is_reversal ? (
                            <Badge variant="outline">Reversal</Badge>
                        ) : null}
                    </div>
                    {entry.reversal_of_entry_id !== null ? (
                        <p className="text-xs text-muted-foreground">
                            Reverses{' '}
                            <Link
                                href={
                                    journalShow({
                                        journalEntry:
                                            entry.reversal_of_entry_id,
                                    }).url
                                }
                                className="underline underline-offset-4"
                            >
                                entry #{entry.reversal_of_entry_id}
                            </Link>
                        </p>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}

function Fact({
    label,
    value,
    mono,
}: {
    label: string;
    value: string;
    mono?: boolean;
}) {
    return (
        <div className="space-y-1">
            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className={mono ? 'font-mono text-sm' : 'text-sm'}>{value}</p>
        </div>
    );
}

JournalShow.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Journal', href: journalIndex().url },
        { title: 'Entry', href: '#' },
    ],
};
