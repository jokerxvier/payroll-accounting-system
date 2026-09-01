import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    Download,
    Loader2,
    Scale,
    Upload,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { Money } from '@/components/money';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type {
    OpeningItemPageProps,
    OpeningItemReconciliationRow,
    OpeningItemRow,
} from '@/types/opening-item';

const PREVIEW_URL = '/admin/opening-items/preview';
const TEMPLATE_URL = '/admin/opening-items/template';

export default function OpeningItemsIndex({
    parsed,
    token,
    sourceFilename,
    booksOpenedOn,
    summary,
    reconciliation = [],
    recordedCount = 0,
}: OpeningItemPageProps) {
    const upload = useForm({ file: null as File | null });
    const confirm = useForm({});

    const submitUpload = (e: FormEvent) => {
        e.preventDefault();
        // Clears a refusal from a previous attempt: Inertia re-renders the
        // same component on the redirect back, so the confirm form keeps its
        // state and a fresh worksheet would inherit the last one's error.
        upload.post(PREVIEW_URL, {
            forceFormData: true,
            onSuccess: () => confirm.clearErrors(),
        });
    };

    const submitConfirm = (e: FormEvent) => {
        e.preventDefault();

        if (!token) {
            return;
        }

        confirm.post(`/admin/opening-items/confirm/${token}`);
    };

    /*
     * `confirm` is typed by its (empty) data, so TypeScript does not know the
     * keys the CONTROLLER refuses under — `file` for a domain refusal, `token`
     * for a preview gone stale. Read off the form instance rather than the
     * page's shared `errors` prop: the upload form also refuses under `file`,
     * and at page level the two are indistinguishable.
     */
    const confirmErrors = confirm.errors as Partial<
        Record<'file' | 'token', string>
    >;
    const confirmError = confirmErrors.file ?? confirmErrors.token ?? null;

    const hasRowErrors = (summary?.error_count ?? 0) > 0;
    const booksAreOpen = !!booksOpenedOn;

    // A mismatch does NOT block the confirm — see the reconciliation panel.
    const canConfirm = !!token && !hasRowErrors && booksAreOpen;

    return (
        <>
            <Head title="Opening items" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Opening items"
                    description="The unpaid invoices and bills behind the receivable and payable your opening balances state. Recording them gives those totals a sub-ledger somebody can actually chase."
                />

                {!booksAreOpen && (
                    <Alert>
                        <CalendarClock className="size-4" />
                        <AlertTitle>Open the books first</AlertTitle>
                        <AlertDescription>
                            <span>
                                These documents explain a receivable that{' '}
                                <Link
                                    href="/admin/opening-balances"
                                    className="font-medium underline underline-offset-4"
                                >
                                    opening balances
                                </Link>{' '}
                                has not stated yet. Post the cutover snapshot,
                                then come back — there is nothing to reconcile
                                against until you do.
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                {recordedCount > 0 && (
                    <Alert>
                        <CheckCircle2 className="size-4" />
                        <AlertTitle>Open items are already recorded</AlertTitle>
                        <AlertDescription>
                            <span>
                                {recordedCount} document
                                {recordedCount === 1 ? '' : 's'} carried in on{' '}
                                {booksOpenedOn}. Void them from{' '}
                                <Link
                                    href="/admin/invoices"
                                    className="font-medium underline underline-offset-4"
                                >
                                    Invoices
                                </Link>{' '}
                                before importing a new set — a second import
                                would double the sub-ledger against an unchanged
                                control account.
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                {/*
                 * Page level, not inside the preview card: one refusal is "no
                 * parsed worksheet in session", which arrives with nothing to
                 * render the card at all.
                 */}
                {confirmError && (
                    <Alert variant="destructive">
                        <AlertCircle className="size-4" />
                        <AlertTitle>
                            These open items were not recorded
                        </AlertTitle>
                        <AlertDescription>{confirmError}</AlertDescription>
                    </Alert>
                )}

                {/* Step 1 — the worksheet */}
                <Card>
                    <CardHeader>
                        <CardTitle>1. Fill in the worksheet</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            The template lists your contacts so the names match
                            what this system already knows. Enter one row per
                            unpaid document, with figures in pesos — 1,234.56,
                            not 123456 — and its own original number. A document
                            that was settled before you moved is not an open
                            item; leave it in the old system.
                        </p>
                        <Button variant="outline" asChild>
                            <a href={TEMPLATE_URL}>
                                <Download className="size-4" />
                                Download template
                            </a>
                        </Button>
                    </CardContent>
                </Card>

                {/* Step 2 — upload */}
                <Card>
                    <CardHeader>
                        <CardTitle>2. Upload it back</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submitUpload}
                            className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end"
                        >
                            <div className="space-y-2">
                                <Label htmlFor="file">Worksheet</Label>
                                <Input
                                    id="file"
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    aria-invalid={!!upload.errors.file}
                                    onChange={(e) =>
                                        upload.setData(
                                            'file',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                                {upload.errors.file && (
                                    <p className="text-sm text-destructive">
                                        {upload.errors.file}
                                    </p>
                                )}
                            </div>

                            <Button
                                type="submit"
                                disabled={
                                    upload.processing || !upload.data.file
                                }
                            >
                                {upload.processing ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Upload className="size-4" />
                                )}
                                Check the documents
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {/* Step 3 — the preview, and the reconciliation */}
                {parsed && summary && (
                    <Card>
                        <CardHeader className="flex-row items-center justify-between gap-4 space-y-0">
                            <CardTitle>
                                3. Review and record
                                {sourceFilename && (
                                    <span className="ml-2 font-normal text-muted-foreground">
                                        {sourceFilename}
                                    </span>
                                )}
                            </CardTitle>
                            {booksOpenedOn && (
                                <Badge variant="outline">
                                    as at {booksOpenedOn}
                                </Badge>
                            )}
                        </CardHeader>

                        <CardContent className="space-y-6">
                            <ReconciliationPanel rows={reconciliation} />

                            {hasRowErrors && (
                                <Alert variant="destructive">
                                    <AlertCircle className="size-4" />
                                    <AlertTitle>
                                        {summary.error_count} row
                                        {summary.error_count === 1
                                            ? ''
                                            : 's'}{' '}
                                        need fixing
                                    </AlertTitle>
                                    <AlertDescription>
                                        Nothing is recorded while any row is
                                        wrong. A part-loaded sub-ledger ties to
                                        nothing, and the difference it reported
                                        would send somebody hunting a
                                        discrepancy that is only the rows that
                                        failed.
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-14">
                                                Row
                                            </TableHead>
                                            <TableHead>Document</TableHead>
                                            <TableHead>Payer</TableHead>
                                            <TableHead>Due</TableHead>
                                            <TableHead className="text-right">
                                                Total
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Paid
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Outstanding
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {parsed.map((row) => (
                                            <PreviewRow
                                                key={row.row_number}
                                                row={row}
                                            />
                                        ))}
                                    </TableBody>
                                    <TableFooter>
                                        <TableRow>
                                            <TableCell colSpan={4}>
                                                {summary.row_count} document
                                                {summary.row_count === 1
                                                    ? ''
                                                    : 's'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                <Money
                                                    amount={
                                                        summary.total_centavos /
                                                        100
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                <Money
                                                    amount={
                                                        summary.already_paid_centavos /
                                                        100
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                <Money
                                                    amount={
                                                        summary.outstanding_centavos /
                                                        100
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    </TableFooter>
                                </Table>
                            </div>

                            <form onSubmit={submitConfirm}>
                                <Button
                                    type="submit"
                                    disabled={!canConfirm || confirm.processing}
                                >
                                    {confirm.processing ? (
                                        <Loader2 className="size-4 animate-spin" />
                                    ) : (
                                        <CheckCircle2 className="size-4" />
                                    )}
                                    Record these open items
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

/**
 * The signature of this page: does the sub-ledger tie to the control account?
 *
 * Stated rather than left to be footed by eye, because it is the one figure
 * that tells a migrating school whether its previous system agreed with
 * itself. Deliberately not a blocker — a difference is a finding, and
 * refusing the import would leave them with no sub-ledger at all rather than
 * one they can investigate.
 */
function ReconciliationPanel({
    rows,
}: {
    rows: OpeningItemReconciliationRow[];
}) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="space-y-3">
            {rows.map((row) => {
                const reconciled = row.is_reconciled;

                return (
                    <div
                        key={row.key}
                        className={cn(
                            'flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4',
                            reconciled
                                ? 'border-success/30 bg-success/5'
                                : 'border-warning/30 bg-warning/5',
                        )}
                    >
                        <div className="flex items-center gap-3">
                            {reconciled ? (
                                <Scale className="size-5 text-success" />
                            ) : (
                                <AlertTriangle className="size-5 text-warning" />
                            )}
                            <div>
                                <p className="font-medium">
                                    {reconciled
                                        ? `${row.label} tie to the opening balance`
                                        : `${row.label} do not tie to the opening balance`}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {reconciled ? (
                                        'Every peso in the control account has a document behind it.'
                                    ) : (
                                        <>
                                            Off by{' '}
                                            <span className="font-medium whitespace-nowrap">
                                                <Money
                                                    amount={
                                                        Math.abs(
                                                            row.difference_centavos,
                                                        ) / 100
                                                    }
                                                />
                                            </span>
                                            .{' '}
                                            {row.difference_centavos > 0
                                                ? 'The control account holds more than these documents explain.'
                                                : 'These documents come to more than the control account holds.'}{' '}
                                            You can still record them — the gap
                                            came from the books you migrated
                                            from.
                                        </>
                                    )}
                                </p>
                            </div>
                        </div>

                        <dl className="flex gap-6 text-sm">
                            <div className="text-right">
                                <dt className="text-muted-foreground">
                                    Opening balance
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    <Money
                                        amount={row.control_centavos / 100}
                                    />
                                </dd>
                            </div>
                            <div className="text-right">
                                <dt className="text-muted-foreground">
                                    These documents
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    <Money amount={row.items_centavos / 100} />
                                </dd>
                            </div>
                        </dl>
                    </div>
                );
            })}
        </div>
    );
}

function PreviewRow({ row }: { row: OpeningItemRow }) {
    const failed = row.errors.length > 0;

    return (
        <>
            <TableRow className={cn(failed && 'bg-destructive/5')}>
                <TableCell className="text-muted-foreground tabular-nums">
                    {row.row_number}
                </TableCell>
                <TableCell>
                    <span className="font-medium">
                        {row.number ?? '(unnumbered)'}
                    </span>
                    <span className="ml-2 text-xs text-muted-foreground uppercase">
                        {row.type === 'purchase' ? 'Bill' : 'Invoice'}
                    </span>
                </TableCell>
                <TableCell>
                    {row.contact_name ?? '—'}
                    {row.student_name && (
                        <span className="block text-xs text-muted-foreground">
                            {row.student_name}
                        </span>
                    )}
                </TableCell>
                <TableCell className="text-muted-foreground">
                    {row.due_date ?? 'No due date'}
                </TableCell>
                <TableCell className="text-right tabular-nums">
                    <Money amount={row.total_centavos / 100} />
                </TableCell>
                <TableCell className="text-right tabular-nums">
                    <Money amount={row.amount_paid_centavos / 100} />
                </TableCell>
                <TableCell className="text-right tabular-nums">
                    <Money
                        amount={
                            (row.total_centavos - row.amount_paid_centavos) /
                            100
                        }
                    />
                </TableCell>
            </TableRow>

            {(failed || row.warnings.length > 0) && (
                <TableRow className={cn(failed && 'bg-destructive/5')}>
                    <TableCell />
                    <TableCell colSpan={6} className="pt-0">
                        {row.errors.map((error) => (
                            <p key={error} className="text-sm text-destructive">
                                {error}
                            </p>
                        ))}
                        {row.warnings.map((warning) => (
                            <p
                                key={warning}
                                className="text-sm text-muted-foreground"
                            >
                                {warning}
                            </p>
                        ))}
                    </TableCell>
                </TableRow>
            )}
        </>
    );
}

OpeningItemsIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/journal-entries' },
        { title: 'Opening items', href: '/admin/opening-items' },
    ],
};
