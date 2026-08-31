import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowRight,
    CalendarClock,
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
import { Checkbox } from '@/components/ui/checkbox';
import { DatePicker } from '@/components/ui/date-picker';
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
    OpeningBalanceRow,
    OpeningBalanceSnapshot,
    OpeningBalanceSummary,
} from '@/types/opening-balance';

interface Props {
    parsed?: OpeningBalanceRow[] | null;
    token?: string | null;
    sourceFilename?: string | null;
    cutoverDate?: string | null;
    summary?: OpeningBalanceSummary | null;
    existingSnapshot?: OpeningBalanceSnapshot | null;
}

const PREVIEW_URL = '/admin/opening-balances/preview';
const TEMPLATE_URL = '/admin/opening-balances/template';

export default function OpeningBalancesIndex({
    parsed,
    token,
    sourceFilename,
    cutoverDate,
    summary,
    existingSnapshot,
}: Props) {
    const upload = useForm({
        file: null as File | null,
        cutover_date: '',
    });

    const confirm = useForm({ plug_to_retained_earnings: false as boolean });

    const submitUpload = (e: FormEvent) => {
        e.preventDefault();
        upload.post(PREVIEW_URL, { forceFormData: true });
    };

    const submitConfirm = (e: FormEvent) => {
        e.preventDefault();

        if (!token) {
            return;
        }

        confirm.post(`/admin/opening-balances/confirm/${token}`);
    };

    const difference = summary?.difference_centavos ?? 0;
    const isBalanced = difference === 0;
    const hasRowErrors = (summary?.error_count ?? 0) > 0;
    const periodIsOpen = summary?.period_is_open ?? false;

    // Confirm is gated on three separate things, and the page says which one
    // is holding it rather than just disabling the button.
    const canConfirm =
        !!token &&
        !hasRowErrors &&
        periodIsOpen &&
        (isBalanced || confirm.data.plug_to_retained_earnings);

    return (
        <>
            <Head title="Opening balances" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Opening balances"
                    description="Bring the balances a school already carried into these books, as one dated entry. Assets, liabilities and equity only — prior trading belongs in Retained Earnings."
                />

                {existingSnapshot && (
                    <Alert>
                        <CalendarClock className="size-4" />
                        <AlertTitle>These books are already open</AlertTitle>
                        <AlertDescription>
                            <span>
                                Opening balances were posted as{' '}
                                <Link
                                    href={`/admin/journal-entries/${existingSnapshot.id}`}
                                    className="font-medium underline underline-offset-4"
                                >
                                    {existingSnapshot.entry_number}
                                </Link>{' '}
                                dated {existingSnapshot.date}. Reverse that
                                entry before importing a new snapshot — a second
                                one would double every balance it touches.
                            </span>
                        </AlertDescription>
                    </Alert>
                )}

                {/* Step 1 — the worksheet */}
                <Card>
                    <CardHeader>
                        <CardTitle>1. Fill in the worksheet</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            The template lists every account that can hold an
                            opening balance. Enter figures in pesos — 1,234.56,
                            not 123456 — in either the debit or the credit
                            column, never both.
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
                            className="grid gap-4 sm:grid-cols-[1fr_auto_auto] sm:items-end"
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

                            <div className="space-y-2">
                                <Label htmlFor="cutover_date">
                                    Balances as at
                                </Label>
                                <DatePicker
                                    id="cutover_date"
                                    value={upload.data.cutover_date}
                                    ariaInvalid={!!upload.errors.cutover_date}
                                    onChange={(value) =>
                                        upload.setData('cutover_date', value)
                                    }
                                    placeholder="Pick the cutover date"
                                />
                                {upload.errors.cutover_date && (
                                    <p className="text-sm text-destructive">
                                        {upload.errors.cutover_date}
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
                                Check the figures
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {/* Step 3 — the preview, and the balance beam */}
                {parsed && summary && (
                    <Card>
                        <CardHeader className="flex-row items-center justify-between gap-4 space-y-0">
                            <CardTitle>
                                3. Review and post
                                {sourceFilename && (
                                    <span className="ml-2 font-normal text-muted-foreground">
                                        {sourceFilename}
                                    </span>
                                )}
                            </CardTitle>
                            {cutoverDate && (
                                <Badge variant="outline">
                                    as at {cutoverDate}
                                </Badge>
                            )}
                        </CardHeader>

                        <CardContent className="space-y-6">
                            {/*
                              The signature of this page. Everything else is a
                              form; this is the one number that decides whether
                              the snapshot can be posted at all, so it is
                              stated rather than left to be footed by eye.
                            */}
                            <div
                                className={cn(
                                    'flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4',
                                    isBalanced
                                        ? 'border-success/30 bg-success/5'
                                        : 'border-destructive/30 bg-destructive/5',
                                )}
                            >
                                <div className="flex items-center gap-3">
                                    <Scale
                                        className={cn(
                                            'size-5',
                                            isBalanced
                                                ? 'text-success'
                                                : 'text-destructive',
                                        )}
                                    />
                                    <div>
                                        <p className="font-medium">
                                            {isBalanced
                                                ? 'Balanced'
                                                : 'Out of balance'}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {isBalanced ? (
                                                'Debits equal credits. This snapshot can be posted.'
                                            ) : (
                                                <>
                                                    Debits exceed credits by{' '}
                                                    <Money
                                                        amount={
                                                            difference / 100
                                                        }
                                                        signed
                                                    />
                                                    {difference < 0 &&
                                                        ' (credits are the larger side)'}
                                                    .
                                                </>
                                            )}
                                        </p>
                                    </div>
                                </div>

                                <dl className="flex gap-6 text-right">
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Debits
                                        </dt>
                                        <dd className="tabular-nums">
                                            <Money
                                                amount={
                                                    summary.total_debit_centavos /
                                                    100
                                                }
                                            />
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Credits
                                        </dt>
                                        <dd className="tabular-nums">
                                            <Money
                                                amount={
                                                    summary.total_credit_centavos /
                                                    100
                                                }
                                            />
                                        </dd>
                                    </div>
                                </dl>
                            </div>

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
                                        Correct them in the worksheet and upload
                                        it again. Nothing is posted until every
                                        row is clean.
                                    </AlertDescription>
                                </Alert>
                            )}

                            {!periodIsOpen && (
                                <Alert variant="destructive">
                                    <CalendarClock className="size-4" />
                                    <AlertTitle>
                                        No open period covers {cutoverDate}
                                    </AlertTitle>
                                    <AlertDescription>
                                        <span>
                                            An entry can only post into an open
                                            accounting period.{' '}
                                            <Link
                                                href="/admin/accounting-periods/create"
                                                className="font-medium underline underline-offset-4"
                                            >
                                                Create the period
                                            </Link>{' '}
                                            that covers this date, then come
                                            back — your worksheet is still here.
                                        </span>
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-16">
                                                Row
                                            </TableHead>
                                            <TableHead className="w-24">
                                                Code
                                            </TableHead>
                                            <TableHead>Account</TableHead>
                                            <TableHead className="text-right">
                                                Debit
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Credit
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {parsed.map((row) => (
                                            <TableRow
                                                key={row.row_number}
                                                className={cn(
                                                    row.errors.length > 0 &&
                                                        'bg-destructive/5',
                                                )}
                                            >
                                                <TableCell className="text-muted-foreground tabular-nums">
                                                    {row.row_number}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {row.account_code ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <span>
                                                        {row.account_name ??
                                                            '—'}
                                                    </span>
                                                    {row.errors.map(
                                                        (error, i) => (
                                                            <p
                                                                key={i}
                                                                className="text-sm text-destructive"
                                                            >
                                                                {error}
                                                            </p>
                                                        ),
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {row.debit_centavos > 0 ? (
                                                        <Money
                                                            amount={
                                                                row.debit_centavos /
                                                                100
                                                            }
                                                        />
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {row.credit_centavos > 0 ? (
                                                        <Money
                                                            amount={
                                                                row.credit_centavos /
                                                                100
                                                            }
                                                        />
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                    <TableFooter>
                                        <TableRow>
                                            <TableCell colSpan={3}>
                                                Total
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                <Money
                                                    amount={
                                                        summary.total_debit_centavos /
                                                        100
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                <Money
                                                    amount={
                                                        summary.total_credit_centavos /
                                                        100
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    </TableFooter>
                                </Table>
                            </div>

                            <form
                                onSubmit={submitConfirm}
                                className="space-y-4"
                            >
                                {!isBalanced && !hasRowErrors && (
                                    <div className="flex items-start gap-3 rounded-md border p-4">
                                        <Checkbox
                                            id="plug"
                                            checked={
                                                confirm.data
                                                    .plug_to_retained_earnings
                                            }
                                            onCheckedChange={(checked) =>
                                                confirm.setData(
                                                    'plug_to_retained_earnings',
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <div className="space-y-1">
                                            <Label
                                                htmlFor="plug"
                                                className="font-medium"
                                            >
                                                Post the difference to Retained
                                                Earnings
                                            </Label>
                                            <p className="text-sm text-muted-foreground">
                                                The gap between what a school
                                                owns and what it owes is its
                                                accumulated result to date. Tick
                                                this only if the figures above
                                                are right and the difference is
                                                genuinely prior trading —
                                                otherwise fix the worksheet.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                <Button
                                    type="submit"
                                    disabled={!canConfirm || confirm.processing}
                                >
                                    {confirm.processing ? (
                                        <Loader2 className="size-4 animate-spin" />
                                    ) : (
                                        <ArrowRight className="size-4" />
                                    )}
                                    Post opening balances
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

OpeningBalancesIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/journal-entries' },
        { title: 'Opening balances', href: '/admin/opening-balances' },
    ],
};
