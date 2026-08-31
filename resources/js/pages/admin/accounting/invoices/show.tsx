import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    Check,
    CheckCircle2,
    Link as LinkIcon,
    Mail,
    Pencil,
    Printer,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
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
import { useClipboard } from '@/hooks/use-clipboard';
import {
    approve as invoiceApprove,
    edit as invoiceEdit,
    index as invoiceIndex,
    payLink as invoicePayLink,
    print as invoicePrint,
    send as invoiceSend,
    // Wayfinder renames the `void` route export, because `void` is a
    // reserved word in JavaScript.
    voidMethod as invoiceVoid,
} from '@/routes/admin/invoices';
import { show as journalShow } from '@/routes/admin/journal-entries';
import { show as paymentShow } from '@/routes/admin/payments';
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
    const [copyingLink, setCopyingLink] = useState(false);
    const [copiedLink, setCopiedLink] = useState(false);
    const [, copyToClipboard] = useClipboard();
    const [sending, setSending] = useState(false);
    /*
     * The address the send goes to, seeded from what the record already knows:
     * the one it last went to on a re-send, the payer's on file otherwise. It
     * stays editable — a family may want this term's bill at a second address,
     * and the contact record is deliberately not rewritten by sending.
     */
    const [recipient, setRecipient] = useState(
        invoice.sent_to ?? invoice.contact?.email ?? '',
    );
    const [sendProcessing, setSendProcessing] = useState(false);
    /*
     * Why the dialog holds its own error rather than reading a flash: a
     * refusal here is about the box the operator is looking at, and a toast
     * that appears after the dialog has closed makes them reopen it, retype
     * nothing, and guess. The server sends anything typing can fix as a
     * validation error on `email`, which also keeps the dialog open.
     */
    const [sendError, setSendError] = useState<string | null>(null);

    const isSales = invoice.type === 'sales';
    const heading = invoice.number ?? `Draft ${isSales ? 'invoice' : 'bill'}`;

    /**
     * Mint the link server-side, then put it on the clipboard.
     *
     * Send by email is the usual route to a parent now. This stays for the
     * cases email is not: a school that talks to its families over Messenger
     * or SMS, or a payer whose address bounces.
     */
    const copyPayLink = (): void => {
        setCopyingLink(true);

        router.post(
            invoicePayLink({ invoice: invoice.id }).url,
            {},
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    // From the re-rendered invoice, not from a flash. The old
                    // read was `flash.payLink`, which nothing ever set —
                    // `HandleFlashToasts` folds every flash key into `toast` —
                    // so this returned early every single time and the button
                    // did nothing at all.
                    const link = (
                        page.props.invoice as InvoiceDetail | undefined
                    )?.pay_url;

                    if (!link) {
                        toast.error('The pay link could not be built.');

                        return;
                    }

                    void copyToClipboard(link).then((copied) => {
                        if (copied) {
                            setCopiedLink(true);
                            toast.success('Pay link copied', {
                                description: link,
                            });
                            window.setTimeout(() => setCopiedLink(false), 3000);

                            return;
                        }

                        // Refusing to copy is not refusing to help: the link
                        // is now on the page under Document, where it can be
                        // selected by hand.
                        toast.info('Pay link ready — copy it below', {
                            description: link,
                        });
                    });
                },
                onFinish: () => setCopyingLink(false),
            },
        );
    };

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

    const handleSend = (): void => {
        setSendProcessing(true);
        setSendError(null);

        router.post(
            invoiceSend({ invoice: invoice.id }).url,
            { email: recipient },
            {
                preserveScroll: true,
                // The success toast is the server's sentence — it names the
                // address the mail actually went to, which is the part worth
                // reading back.
                onSuccess: () => setSending(false),
                onError: (errors) => {
                    setSendError(errors.email ?? null);

                    // Only when there is nothing to show beside the field.
                    // Both at once says the same thing twice, in two places,
                    // one of which disappears.
                    if (!errors.email) {
                        toast.error('Could not send this invoice.');
                    }
                },
                onFinish: () => setSendProcessing(false),
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

                            {/*
                              Mints the customer-facing link on first press
                              and copies it. Only for an issued sales invoice
                              — there is nobody to send a bill's link to, and
                              a draft has nothing to collect.
                            */}
                            {isSales && !invoice.can.approve ? (
                                <Button
                                    variant="outline"
                                    onClick={copyPayLink}
                                    disabled={copyingLink}
                                >
                                    {copiedLink ? (
                                        <Check className="mr-1 h-4 w-4" />
                                    ) : (
                                        <LinkIcon className="mr-1 h-4 w-4" />
                                    )}
                                    {copiedLink
                                        ? 'Link copied'
                                        : 'Copy pay link'}
                                </Button>
                            ) : null}

                            {/*
                              Sends the same tokenised link the copy button
                              hands over, but to an address rather than to the
                              clipboard. Sales only and issued only — the
                              policy decides, this just reads its answer.
                            */}
                            {invoice.can.send ? (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setSendError(null);
                                        setSending(true);
                                    }}
                                >
                                    <Mail className="mr-1 h-4 w-4" />
                                    {invoice.sent_at
                                        ? 'Send again'
                                        : 'Send by email'}
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

                                {/*
                                  The send record, in the place a delivery
                                  dispute gets settled: a parent saying the
                                  invoice never arrived is answered by the
                                  address it went to, not by the timestamp.
                                */}
                                {invoice.sent_at ? (
                                    <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">
                                        Emailed to{' '}
                                        {invoice.sent_to ?? 'the payer'} on{' '}
                                        {formatSentAt(invoice.sent_at)}.
                                    </p>
                                ) : null}

                                {/*
                                  Shown, not just copied. A clipboard write can
                                  fail for reasons the operator cannot act on —
                                  the API does not exist outside a secure
                                  context, and this app is served over plain
                                  http on a .test domain in development — so
                                  the link has to be somewhere it can be
                                  selected by hand. It also lets someone check
                                  they are about to send the right one.
                                */}
                                {invoice.pay_url ? (
                                    <div className="mt-4 space-y-1 border-t pt-3">
                                        <p className="text-xs text-muted-foreground">
                                            Pay link
                                        </p>
                                        <p
                                            data-testid="pay-link"
                                            className="font-mono text-xs break-all text-muted-foreground select-all"
                                        >
                                            {invoice.pay_url}
                                        </p>
                                    </div>
                                ) : null}

                                {invoice.void_reason ? (
                                    <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">
                                        Voided: {invoice.void_reason}
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>

                        {invoice.payments.length > 0 ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Payments</CardTitle>
                                    <CardDescription>
                                        Applied to this document.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <dl className="space-y-2 text-sm">
                                        {invoice.payments.map((payment) => (
                                            <div
                                                key={payment.id}
                                                className="flex items-center justify-between gap-4"
                                            >
                                                <dt>
                                                    <Link
                                                        className="font-mono text-xs underline underline-offset-4"
                                                        href={
                                                            paymentShow({
                                                                payment:
                                                                    payment.payment_id,
                                                            }).url
                                                        }
                                                    >
                                                        {payment.reference ??
                                                            `#${payment.payment_id}`}
                                                    </Link>
                                                    <span className="ml-2 text-xs text-muted-foreground tabular-nums">
                                                        {payment.payment_date}
                                                    </span>
                                                </dt>
                                                <dd>
                                                    <Money
                                                        amount={
                                                            payment.amount_centavos /
                                                            100
                                                        }
                                                    />
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </CardContent>
                            </Card>
                        ) : null}

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
                            This posts the document to the ledger. After that it
                            cannot be edited — correcting it means voiding it
                            and raising a replacement.
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

            <AlertDialog
                open={sending}
                onOpenChange={(open) => {
                    if (!open) {
                        setSending(false);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Send {invoice.number ?? 'this invoice'} by email?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            The payer gets the amount, the due date and a link
                            that pays this invoice online. The link is theirs
                            alone, so check the address before it goes.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="send_email">Send to</Label>
                        <Input
                            id="send_email"
                            type="email"
                            value={recipient}
                            maxLength={160}
                            placeholder="parent@example.com"
                            aria-invalid={!!sendError}
                            onChange={(e) => {
                                setRecipient(e.target.value);
                                // The complaint was about what was in the box;
                                // it stops being true the moment that changes.
                                setSendError(null);
                            }}
                        />
                        <InputError message={sendError ?? undefined} />
                        {/*
                          Two states worth naming rather than leaving the
                          operator to infer from an empty box: the payer has
                          no address on file, or this is a second send and the
                          box is showing where the first one went.
                        */}
                        {invoice.contact?.email ? null : (
                            <p className="text-sm text-muted-foreground">
                                {invoice.contact?.name ?? 'This customer'} has
                                no email address on file. What you type here is
                                used for this send only.
                            </p>
                        )}
                        {invoice.sent_to ? (
                            <p className="text-sm text-muted-foreground">
                                Last sent to {invoice.sent_to}.
                            </p>
                        ) : null}
                    </div>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={sendProcessing}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={sendProcessing || recipient.trim() === ''}
                            onClick={(event) => {
                                event.preventDefault();
                                handleSend();
                            }}
                        >
                            {sendProcessing ? 'Sending…' : 'Send invoice'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

/**
 * An ISO timestamp as a person reads it — '3 Sep 2026, 2:15 pm'.
 *
 * `en-GB` for the same reason the payslip pins it: `en-PH` puts the month
 * first on a long date, which disagrees with every other date on the page.
 */
function formatSentAt(iso: string): string {
    const parsed = new Date(iso);

    if (Number.isNaN(parsed.getTime())) {
        return iso;
    }

    return parsed.toLocaleString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
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
