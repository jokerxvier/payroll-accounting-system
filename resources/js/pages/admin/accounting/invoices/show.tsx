import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Ban, CheckCircle2, Pencil, Printer } from 'lucide-react';
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
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    approve as invoiceApprove,
    edit as invoiceEdit,
    index as invoiceIndex,
    print as invoicePrint,
    // Wayfinder renames the `void` route export, because `void` is a
    // reserved word in JavaScript.
    voidMethod as invoiceVoid,
} from '@/routes/admin/invoices';
import { show as journalShow } from '@/routes/admin/journal-entries';
import type { InvoiceDetail } from '@/types';
import { InvoiceStatusBadge } from './index';

interface Props {
    invoice: InvoiceDetail;
}

export default function InvoiceShow({ invoice }: Props) {
    const [confirmingApprove, setConfirmingApprove] = useState(false);
    const [voiding, setVoiding] = useState(false);
    const [voidReason, setVoidReason] = useState('');
    const [processing, setProcessing] = useState(false);

    const isSales = invoice.type === 'sales';
    const heading = invoice.number ?? `Draft ${isSales ? 'invoice' : 'bill'}`;

    const handleApprove = (): void => {
        setProcessing(true);

        router.post(
            invoiceApprove({ invoice: invoice.id }).url,
            {},
            {
                onSuccess: () => setConfirmingApprove(false),
                onError: () => toast.error('Could not approve this document.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const handleVoid = (): void => {
        setProcessing(true);

        router.post(
            invoiceVoid({ invoice: invoice.id }).url,
            { reason: voidReason },
            {
                onSuccess: () => setVoiding(false),
                onError: () => toast.error('Could not void this document.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Head title={heading} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={heading}
                    description={
                        invoice.contact?.name ??
                        'No counterparty on this document.'
                    }
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild variant="outline">
                                <Link
                                    href={
                                        invoiceIndex({
                                            query: { type: invoice.type },
                                        }).url
                                    }
                                >
                                    <ArrowLeft className="mr-1 h-4 w-4" />
                                    Back
                                </Link>
                            </Button>

                            {invoice.can.print ? (
                                <Button asChild variant="outline">
                                    <a
                                        href={
                                            invoicePrint({
                                                invoice: invoice.id,
                                            }).url
                                        }
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <Printer className="mr-1 h-4 w-4" />
                                        Print
                                    </a>
                                </Button>
                            ) : null}

                            {invoice.can.update ? (
                                <Button asChild variant="outline">
                                    <Link
                                        href={
                                            invoiceEdit({
                                                invoice: invoice.id,
                                            }).url
                                        }
                                    >
                                        <Pencil className="mr-1 h-4 w-4" />
                                        Edit draft
                                    </Link>
                                </Button>
                            ) : null}

                            {invoice.can.void ? (
                                <Button
                                    variant="outline"
                                    onClick={() => setVoiding(true)}
                                >
                                    <Ban className="mr-1 h-4 w-4" />
                                    Void
                                </Button>
                            ) : null}

                            {invoice.can.approve ? (
                                <Button
                                    onClick={() => setConfirmingApprove(true)}
                                >
                                    <CheckCircle2 className="mr-1 h-4 w-4" />
                                    Approve
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Lines</CardTitle>
                            <CardDescription>
                                Amounts shown are net of VAT. The tax is
                                totalled once, below.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <Table className="text-sm">
                                    <TableHeader>
                                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Description
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Account
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Qty
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Unit price
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                VAT
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Amount
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {invoice.lines.map((line) => (
                                            <TableRow key={line.id}>
                                                <TableCell>
                                                    {line.description}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs text-muted-foreground">
                                                    {line.account_code ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {trimQuantity(
                                                        line.quantity,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Money
                                                        amount={
                                                            line.unit_price_centavos /
                                                            100
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="font-mono text-xs text-muted-foreground">
                                                    {line.tax_rate_label ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Money
                                                        amount={
                                                            line.line_net_centavos /
                                                            100
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

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Totals</CardTitle>
                                <CardDescription>
                                    Reported separately because the BIR return
                                    reads them separately.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <dl className="space-y-2 text-sm">
                                    <TotalRow
                                        label="VATable sales"
                                        centavos={
                                            invoice.vatable_sales_centavos
                                        }
                                    />
                                    <TotalRow
                                        label="VAT-exempt sales"
                                        centavos={
                                            invoice.vat_exempt_sales_centavos
                                        }
                                    />
                                    <TotalRow
                                        label="Zero-rated sales"
                                        centavos={
                                            invoice.zero_rated_sales_centavos
                                        }
                                    />
                                    <TotalRow
                                        label="VAT"
                                        centavos={invoice.vat_centavos}
                                    />
                                    <div className="flex items-center justify-between border-t pt-2 text-base font-medium">
                                        <dt>Total</dt>
                                        <dd>
                                            <Money
                                                amount={
                                                    invoice.total_centavos / 100
                                                }
                                            />
                                        </dd>
                                    </div>
                                    {invoice.amount_paid_centavos !== 0 ? (
                                        <>
                                            <TotalRow
                                                label="Paid"
                                                centavos={
                                                    invoice.amount_paid_centavos
                                                }
                                            />
                                            <TotalRow
                                                label="Balance due"
                                                centavos={
                                                    invoice.balance_due_centavos
                                                }
                                            />
                                        </>
                                    ) : null}
                                </dl>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Document</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="space-y-2 text-sm">
                                    <DetailRow label="Status">
                                        <InvoiceStatusBadge
                                            status={invoice.status}
                                        />
                                    </DetailRow>
                                    <DetailRow label="Issued">
                                        <span className="tabular-nums">
                                            {invoice.issue_date}
                                        </span>
                                    </DetailRow>
                                    <DetailRow label="Due">
                                        <span className="tabular-nums">
                                            {invoice.due_date ?? '—'}
                                        </span>
                                    </DetailRow>
                                    <DetailRow label="Reference">
                                        {invoice.reference ?? '—'}
                                    </DetailRow>
                                    <DetailRow label="Prices">
                                        {invoice.is_vat_inclusive
                                            ? 'VAT-inclusive'
                                            : 'VAT-exclusive'}
                                    </DetailRow>
                                    <DetailRow label="Ledger">
                                        {invoice.journal_entry ? (
                                            <Link
                                                className="font-mono text-xs underline underline-offset-4"
                                                href={
                                                    journalShow({
                                                        journalEntry:
                                                            invoice
                                                                .journal_entry
                                                                .id,
                                                    }).url
                                                }
                                            >
                                                {invoice.journal_entry
                                                    .entry_number ?? 'View'}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Not posted
                                            </span>
                                        )}
                                    </DetailRow>
                                </dl>

                                {invoice.void_reason ? (
                                    <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">
                                        Voided: {invoice.void_reason}
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>

                        {invoice.notes ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Internal notes</CardTitle>
                                    <CardDescription>
                                        Not printed on the document.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="text-sm whitespace-pre-line">
                                    {invoice.notes}
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </div>
            </div>

            <AlertDialog
                open={confirmingApprove}
                onOpenChange={(open) => {
                    if (!open) {
                        setConfirmingApprove(false);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Approve this {isSales ? 'invoice' : 'bill'}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This takes the next serial and posts the document to
                            the ledger. After that it cannot be edited —
                            correcting it means voiding it and raising a
                            replacement.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={processing}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={processing}
                            onClick={(event) => {
                                event.preventDefault();
                                handleApprove();
                            }}
                        >
                            {processing ? 'Approving…' : 'Approve and post'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog
                open={voiding}
                onOpenChange={(open) => {
                    if (!open) {
                        setVoiding(false);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Void {invoice.number ?? 'this document'}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The ledger entry is reversed and both sides stay on
                            the books. The number is kept on record rather than
                            reused — a missing serial reads as a document issued
                            and hidden.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="void_reason">Reason</Label>
                        <Input
                            id="void_reason"
                            value={voidReason}
                            maxLength={255}
                            placeholder="Billed in error"
                            onChange={(e) => setVoidReason(e.target.value)}
                        />
                    </div>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={processing}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            disabled={processing}
                            onClick={(event) => {
                                event.preventDefault();
                                handleVoid();
                            }}
                        >
                            {processing ? 'Voiding…' : 'Void document'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

/** 2.5000 → 2.5, 1.0000 → 1. */
function trimQuantity(quantity: string): string {
    return quantity.includes('.') ? quantity.replace(/\.?0+$/, '') : quantity;
}

function TotalRow({ label, centavos }: { label: string; centavos: number }) {
    return (
        <div className="flex items-center justify-between">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>
                <Money amount={centavos / 100} />
            </dd>
        </div>
    );
}

function DetailRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">{children}</dd>
        </div>
    );
}

InvoiceShow.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Invoices', href: invoiceIndex().url },
        { title: 'Document', href: '#' },
    ],
};
