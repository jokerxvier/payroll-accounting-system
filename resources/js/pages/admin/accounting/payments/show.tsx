import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Ban, CheckCircle2, Pencil } from 'lucide-react';
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
import { show as invoiceShow } from '@/routes/admin/invoices';
import { show as journalShow } from '@/routes/admin/journal-entries';
import {
    edit as paymentEdit,
    index as paymentIndex,
    post as paymentPost,
    voidMethod as paymentVoid,
} from '@/routes/admin/payments';
import type { PaymentDetail } from '@/types';
import { PaymentStatusBadge } from './index';

interface Props {
    payment: PaymentDetail;
}

export default function PaymentShow({ payment }: Props) {
    const [confirmingPost, setConfirmingPost] = useState(false);
    const [voiding, setVoiding] = useState(false);
    const [voidReason, setVoidReason] = useState('');
    const [processing, setProcessing] = useState(false);

    const isReceipt = payment.type === 'receipt';
    const heading = payment.reference ?? `Payment #${payment.id}`;

    const handlePost = (): void => {
        setProcessing(true);

        router.post(
            paymentPost({ payment: payment.id }).url,
            {},
            {
                onSuccess: () => setConfirmingPost(false),
                onError: () => toast.error('Could not post this payment.'),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const handleVoid = (): void => {
        setProcessing(true);

        router.post(
            paymentVoid({ payment: payment.id }).url,
            { reason: voidReason },
            {
                onSuccess: () => setVoiding(false),
                onError: () => toast.error('Could not void this payment.'),
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
                        payment.contact?.name ??
                        'No counterparty on this payment.'
                    }
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild variant="outline">
                                <Link
                                    href={
                                        paymentIndex({
                                            query: { type: payment.type },
                                        }).url
                                    }
                                >
                                    <ArrowLeft className="mr-1 h-4 w-4" />
                                    Back
                                </Link>
                            </Button>

                            {payment.can.update ? (
                                <Button asChild variant="outline">
                                    <Link
                                        href={
                                            paymentEdit({
                                                payment: payment.id,
                                            }).url
                                        }
                                    >
                                        <Pencil className="mr-1 h-4 w-4" />
                                        Edit draft
                                    </Link>
                                </Button>
                            ) : null}

                            {payment.can.void ? (
                                <Button
                                    variant="outline"
                                    onClick={() => setVoiding(true)}
                                >
                                    <Ban className="mr-1 h-4 w-4" />
                                    Void
                                </Button>
                            ) : null}

                            {payment.can.post ? (
                                <Button onClick={() => setConfirmingPost(true)}>
                                    <CheckCircle2 className="mr-1 h-4 w-4" />
                                    Post
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Applied to</CardTitle>
                            <CardDescription>
                                {payment.allocations.length === 0
                                    ? 'Nothing. The whole amount is held as an advance.'
                                    : 'The documents this payment settles.'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            {payment.allocations.length === 0 ? (
                                <p className="px-6 pb-6 text-sm text-muted-foreground">
                                    Held against the counterparty&rsquo;s
                                    account until a document is raised.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <Table className="text-sm">
                                        <TableHeader>
                                            <TableRow className="bg-muted/40 hover:bg-muted/40">
                                                <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                    Document
                                                </TableHead>
                                                <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                    Status
                                                </TableHead>
                                                <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                    Document total
                                                </TableHead>
                                                <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                    Applied
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {payment.allocations.map(
                                                (allocation) => (
                                                    <TableRow
                                                        key={allocation.id}
                                                    >
                                                        <TableCell>
                                                            <Link
                                                                className="font-mono text-xs underline underline-offset-4"
                                                                href={
                                                                    invoiceShow(
                                                                        {
                                                                            invoice:
                                                                                allocation.invoice_id,
                                                                        },
                                                                    ).url
                                                                }
                                                            >
                                                                {allocation.invoice_number ??
                                                                    `#${allocation.invoice_id}`}
                                                            </Link>
                                                        </TableCell>
                                                        <TableCell className="text-muted-foreground">
                                                            {allocation.invoice_status ??
                                                                '—'}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            <Money
                                                                amount={
                                                                    (allocation.invoice_total_centavos ??
                                                                        0) / 100
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            <Money
                                                                amount={
                                                                    allocation.amount_centavos /
                                                                    100
                                                                }
                                                            />
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Amounts</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="space-y-2 text-sm">
                                    <Row
                                        label={isReceipt ? 'Received' : 'Paid'}
                                        centavos={payment.amount_centavos}
                                    />
                                    <Row
                                        label="Applied"
                                        centavos={payment.allocated_centavos}
                                    />
                                    <div className="flex items-center justify-between border-t pt-2 font-medium">
                                        <dt>Held as an advance</dt>
                                        <dd>
                                            <Money
                                                amount={
                                                    payment.unallocated_centavos /
                                                    100
                                                }
                                            />
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Payment</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="space-y-2 text-sm">
                                    <Detail label="Status">
                                        <PaymentStatusBadge
                                            status={payment.status}
                                        />
                                    </Detail>
                                    <Detail label="Date">
                                        <span className="tabular-nums">
                                            {payment.payment_date}
                                        </span>
                                    </Detail>
                                    <Detail label="Method">
                                        {payment.method.replace('_', ' ')}
                                    </Detail>
                                    <Detail
                                        label={
                                            isReceipt
                                                ? 'Received into'
                                                : 'Paid from'
                                        }
                                    >
                                        {payment.cash_account_name ?? '—'}
                                    </Detail>
                                    <Detail label="Ledger">
                                        {payment.journal_entry ? (
                                            <Link
                                                className="font-mono text-xs underline underline-offset-4"
                                                href={
                                                    journalShow({
                                                        journalEntry:
                                                            payment
                                                                .journal_entry
                                                                .id,
                                                    }).url
                                                }
                                            >
                                                {payment.journal_entry
                                                    .entry_number ?? 'View'}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Not posted
                                            </span>
                                        )}
                                    </Detail>
                                </dl>

                                {payment.void_reason ? (
                                    <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">
                                        Voided: {payment.void_reason}
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>

                        {payment.notes ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Notes</CardTitle>
                                </CardHeader>
                                <CardContent className="text-sm whitespace-pre-line">
                                    {payment.notes}
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </div>
            </div>

            <AlertDialog
                open={confirmingPost}
                onOpenChange={(open) => {
                    if (!open) {
                        setConfirmingPost(false);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Post this payment?</AlertDialogTitle>
                        <AlertDialogDescription>
                            The money reaches the ledger and every document
                            listed is settled by the amount applied to it. After
                            that the payment cannot be edited — undoing it means
                            voiding it, which reverses the entry.
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
                                handlePost();
                            }}
                        >
                            {processing ? 'Posting…' : 'Post payment'}
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
                        <AlertDialogTitle>Void {heading}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            The ledger entry is reversed and both sides stay on
                            the books. Every document this payment settled goes
                            back to owing what it owed — the record of what was
                            applied is kept, it simply stops counting.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="void_reason">Reason</Label>
                        <Input
                            id="void_reason"
                            value={voidReason}
                            maxLength={255}
                            placeholder="Cheque bounced"
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
                            {processing ? 'Voiding…' : 'Void payment'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

function Row({ label, centavos }: { label: string; centavos: number }) {
    return (
        <div className="flex items-center justify-between">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>
                <Money amount={centavos / 100} />
            </dd>
        </div>
    );
}

function Detail({
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

PaymentShow.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Payments', href: paymentIndex().url },
        { title: 'Payment', href: '#' },
    ],
};
