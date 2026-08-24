import { Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Money } from '@/components/money';
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
import { cn } from '@/lib/utils';
import {
    create as paymentCreate,
    edit as paymentEdit,
    index as paymentIndex,
    store as paymentStore,
    update as paymentUpdate,
} from '@/routes/admin/payments';
import type {
    PaymentEditable,
    PaymentFormOptions,
    PaymentMethod,
    PaymentType,
} from '@/types';

/**
 * Record money in or out, and say which documents it settles.
 *
 * The allocation grid is the point of this screen. It lists everything the
 * chosen counterparty still owes on, oldest first, with the outstanding
 * balance beside an amount box — so the operator is choosing from what is
 * actually open rather than typing document numbers from memory.
 *
 * The unallocated figure is shown at all times and is deliberately not an
 * error. Money arrives in round numbers, and the remainder is an advance
 * that posts to its own account. Calling that a validation failure would
 * push people into inventing a document to absorb it.
 *
 * Every amount here is the server's to verify: allocation bounds are checked
 * against a live remaining balance inside the writing transaction, because a
 * figure this page loaded a minute ago can be stale by the time it is
 * submitted.
 */

const METHOD_LABELS: Record<PaymentMethod, string> = {
    cash: 'Cash',
    cheque: 'Cheque',
    bank_transfer: 'Bank transfer',
    online: 'Online',
    other: 'Other',
};

type Mode =
    | { kind: 'create'; type: PaymentType }
    | { kind: 'edit'; payment: PaymentEditable };

interface PaymentFormProps extends PaymentFormOptions {
    mode: Mode;
}

interface FormShape {
    type: PaymentType;
    contact_id: number | null;
    payment_date: string;
    amount_centavos: number;
    cash_account_id: number | null;
    method: PaymentMethod;
    reference: string;
    notes: string;
    allocations: Array<{ invoice_id: number; amount_centavos: number }>;
    [key: string]:
        | string
        | number
        | null
        | Array<{ invoice_id: number; amount_centavos: number }>;
}

/**
 * Digits, at most one decimal point, at most two places after it.
 *
 * A gate on each keystroke rather than sanitising afterwards, so a rejected
 * character never appears. A trailing point ("1234.") passes — it is a
 * legitimate half-finished entry.
 */
const AMOUNT_PATTERN = /^\d*\.?\d{0,2}$/;

function centavosToPesos(centavos: number): string {
    return centavos === 0 ? '' : (centavos / 100).toFixed(2);
}

function pesosToCentavos(input: string): number {
    const cleaned = input.trim();

    if (cleaned === '' || cleaned === '.') {
        return 0;
    }

    const parsed = Number(cleaned);

    return Number.isNaN(parsed) ? 0 : Math.round(parsed * 100);
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function buildDefaults(mode: Mode): FormShape {
    if (mode.kind === 'create') {
        return {
            type: mode.type,
            contact_id: null,
            payment_date: today(),
            amount_centavos: 0,
            cash_account_id: null,
            method: 'cash',
            reference: '',
            notes: '',
            allocations: [],
        };
    }

    const payment = mode.payment;

    return {
        type: payment.type,
        contact_id: payment.contact_id,
        payment_date: payment.payment_date,
        amount_centavos: payment.amount_centavos,
        cash_account_id: payment.cash_account_id,
        method: payment.method,
        reference: payment.reference ?? '',
        notes: payment.notes ?? '',
        allocations: payment.allocations,
    };
}

export function PaymentForm({
    mode,
    contactOptions,
    cashAccountOptions,
    outstandingInvoices,
}: PaymentFormProps) {
    const form = useForm<FormShape>(buildDefaults(mode));
    const isEdit = mode.kind === 'edit';
    const isReceipt = form.data.type === 'receipt';

    // Raw text for the total, driven by its own state rather than derived
    // from the parsed centavos. Deriving it means every keystroke reformats
    // the field — typing "5" becomes "5.00" and the next character lands
    // after the decimals — so a multi-digit figure cannot be typed at all.
    // Third time this pattern has been needed; see journal-entry-form.tsx.
    const [rawAmount, setRawAmount] = useState<string>(() =>
        centavosToPesos(form.data.amount_centavos),
    );

    // Per-invoice raw text, keyed by invoice id rather than by row index so
    // the mapping survives the list reordering or a row dropping out.
    const [rawAllocations, setRawAllocations] = useState<
        Record<number, string>
    >(() =>
        Object.fromEntries(
            form.data.allocations.map((a) => [
                a.invoice_id,
                centavosToPesos(a.amount_centavos),
            ]),
        ),
    );

    const allocatedByInvoice = useMemo(() => {
        const map = new Map<number, number>();
        form.data.allocations.forEach((a) =>
            map.set(a.invoice_id, a.amount_centavos),
        );

        return map;
    }, [form.data.allocations]);

    const allocatedTotal = useMemo(
        () =>
            form.data.allocations.reduce(
                (sum, a) => sum + a.amount_centavos,
                0,
            ),
        [form.data.allocations],
    );

    const unallocated = form.data.amount_centavos - allocatedTotal;
    const overAllocated = unallocated < 0;

    /** Reload the page's options once a counterparty is chosen. */
    const onContactChange = (value: string): void => {
        const contactId = Number(value);
        form.setData('contact_id', contactId);

        const target = isEdit
            ? paymentEdit({
                  payment: (mode as { payment: PaymentEditable }).payment.id,
              }).url
            : paymentCreate().url;

        // Partial reload: the grid needs this contact's open documents, and
        // asking the server beats shipping every open document in the school
        // to every visitor of this page.
        router.get(
            target,
            { type: form.data.type, contact_id: contactId },
            {
                only: ['outstandingInvoices'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const setAmount = (input: string): void => {
        if (!AMOUNT_PATTERN.test(input)) {
            return;
        }

        setRawAmount(input);
        form.setData('amount_centavos', pesosToCentavos(input));
    };

    const setAllocation = (invoiceId: number, input: string): void => {
        if (!AMOUNT_PATTERN.test(input)) {
            return;
        }

        setRawAllocations((prev) => ({ ...prev, [invoiceId]: input }));

        const centavos = pesosToCentavos(input);
        const without = form.data.allocations.filter(
            (a) => a.invoice_id !== invoiceId,
        );

        // A zero allocation is dropped rather than sent. The server refuses
        // one, and an empty box plainly means "not paying this".
        form.setData(
            'allocations',
            centavos > 0
                ? [
                      ...without,
                      { invoice_id: invoiceId, amount_centavos: centavos },
                  ]
                : without,
        );
    };

    /**
     * Spread the payment across the open documents, oldest first, until it
     * runs out. The order money is normally applied in, and the reason the
     * server hands the list back sorted by issue date.
     */
    const allocateOldestFirst = (): void => {
        let remaining = form.data.amount_centavos;
        const next: Array<{ invoice_id: number; amount_centavos: number }> = [];
        const raw: Record<number, string> = {};

        for (const invoice of outstandingInvoices) {
            if (remaining <= 0) {
                break;
            }

            const take = Math.min(remaining, invoice.balance_due_centavos);

            if (take > 0) {
                next.push({ invoice_id: invoice.id, amount_centavos: take });
                raw[invoice.id] = centavosToPesos(take);
                remaining -= take;
            }
        }

        form.setData('allocations', next);
        setRawAllocations(raw);
    };

    const clearAllocations = (): void => {
        form.setData('allocations', []);
        setRawAllocations({});
    };

    const handleSubmit = (event: FormEvent): void => {
        event.preventDefault();

        const options = {
            onError: () => {
                toast.error('Check the highlighted fields and try again.');
            },
        };

        if (isEdit) {
            form.put(paymentUpdate({ payment: mode.payment.id }).url, options);

            return;
        }

        form.post(paymentStore().url, options);
    };

    const noun = isReceipt ? 'receipt' : 'disbursement';

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Details</CardTitle>
                    <CardDescription>
                        {isReceipt
                            ? 'Money received from a customer, and which invoices it settles.'
                            : 'Money paid to a supplier, and which bills it settles.'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="contact_id">
                            {isReceipt ? 'Received from' : 'Paid to'}
                        </Label>
                        <Select
                            value={
                                form.data.contact_id === null
                                    ? ''
                                    : String(form.data.contact_id)
                            }
                            onValueChange={onContactChange}
                        >
                            <SelectTrigger id="contact_id">
                                <SelectValue
                                    placeholder={`Choose a ${isReceipt ? 'customer' : 'supplier'}`}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {contactOptions.map((contact) => (
                                    <SelectItem
                                        key={contact.id}
                                        value={String(contact.id)}
                                    >
                                        {contact.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.contact_id} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="amount">Amount</Label>
                        <Input
                            id="amount"
                            value={rawAmount}
                            inputMode="decimal"
                            placeholder="0.00"
                            className="text-right tabular-nums"
                            onChange={(e) => setAmount(e.target.value)}
                        />
                        <InputError message={form.errors.amount_centavos} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="payment_date">Date</Label>
                        <Input
                            id="payment_date"
                            type="date"
                            value={form.data.payment_date}
                            onChange={(e) =>
                                form.setData('payment_date', e.target.value)
                            }
                        />
                        <InputError message={form.errors.payment_date} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="cash_account_id">
                            {isReceipt ? 'Received into' : 'Paid from'}
                        </Label>
                        <Select
                            value={
                                form.data.cash_account_id === null
                                    ? ''
                                    : String(form.data.cash_account_id)
                            }
                            onValueChange={(value) =>
                                form.setData('cash_account_id', Number(value))
                            }
                        >
                            <SelectTrigger id="cash_account_id">
                                <SelectValue placeholder="Choose an account" />
                            </SelectTrigger>
                            <SelectContent>
                                {cashAccountOptions.map((account) => (
                                    <SelectItem
                                        key={account.id}
                                        value={String(account.id)}
                                    >
                                        {account.code} · {account.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.cash_account_id} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="method">Method</Label>
                        <Select
                            value={form.data.method}
                            onValueChange={(value) =>
                                form.setData('method', value as PaymentMethod)
                            }
                        >
                            <SelectTrigger id="method">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {(
                                    Object.keys(
                                        METHOD_LABELS,
                                    ) as PaymentMethod[]
                                ).map((method) => (
                                    <SelectItem key={method} value={method}>
                                        {METHOD_LABELS[method]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.method} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="reference">Reference</Label>
                        <Input
                            id="reference"
                            value={form.data.reference}
                            maxLength={64}
                            placeholder="Cheque no., bank reference, or receipt no."
                            onChange={(e) =>
                                form.setData('reference', e.target.value)
                            }
                        />
                        <InputError message={form.errors.reference} />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Apply to</CardTitle>
                    <CardDescription>
                        {form.data.contact_id === null
                            ? `Choose a ${isReceipt ? 'customer' : 'supplier'} to see what they have outstanding.`
                            : outstandingInvoices.length === 0
                              ? `Nothing outstanding. The whole ${noun} will be held as an advance until a document is raised.`
                              : 'Leave a box empty to skip that document. Anything left over is held as an advance.'}
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4 p-0 sm:p-0">
                    {outstandingInvoices.length > 0 ? (
                        <>
                            <div className="overflow-x-auto">
                                <Table className="text-sm">
                                    <TableHeader>
                                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Document
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Issued
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Total
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Outstanding
                                            </TableHead>
                                            <TableHead className="w-[10rem] text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Apply
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {outstandingInvoices.map((invoice) => {
                                            const applied =
                                                allocatedByInvoice.get(
                                                    invoice.id,
                                                ) ?? 0;
                                            const exceeds =
                                                applied >
                                                invoice.balance_due_centavos;

                                            return (
                                                <TableRow key={invoice.id}>
                                                    <TableCell className="font-mono text-xs">
                                                        {invoice.number ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground tabular-nums">
                                                        {invoice.issue_date}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Money
                                                            amount={
                                                                invoice.total_centavos /
                                                                100
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Money
                                                            amount={
                                                                invoice.balance_due_centavos /
                                                                100
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <Input
                                                            value={
                                                                rawAllocations[
                                                                    invoice.id
                                                                ] ?? ''
                                                            }
                                                            inputMode="decimal"
                                                            placeholder="0.00"
                                                            aria-label={`Apply to ${invoice.number ?? `document ${invoice.id}`}`}
                                                            className={cn(
                                                                'text-right tabular-nums',
                                                                exceeds &&
                                                                    'border-destructive',
                                                            )}
                                                            onChange={(e) =>
                                                                setAllocation(
                                                                    invoice.id,
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>

                            <div className="flex flex-wrap items-center gap-2 px-4 pb-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={allocateOldestFirst}
                                >
                                    Allocate oldest first
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={clearAllocations}
                                >
                                    Clear
                                </Button>
                            </div>
                        </>
                    ) : null}

                    <div className="border-t px-4 py-3">
                        <dl className="space-y-2 text-sm">
                            <div className="flex items-center justify-between">
                                <dt className="text-muted-foreground">
                                    {isReceipt ? 'Received' : 'Paid'}
                                </dt>
                                <dd>
                                    <Money
                                        amount={form.data.amount_centavos / 100}
                                    />
                                </dd>
                            </div>
                            <div className="flex items-center justify-between">
                                <dt className="text-muted-foreground">
                                    Applied
                                </dt>
                                <dd>
                                    <Money amount={allocatedTotal / 100} />
                                </dd>
                            </div>
                            <div
                                className={cn(
                                    'flex items-center justify-between border-t pt-2 font-medium',
                                    overAllocated && 'text-destructive',
                                )}
                            >
                                <dt>
                                    {overAllocated
                                        ? 'Over-applied by'
                                        : 'Held as an advance'}
                                </dt>
                                <dd>
                                    <Money
                                        amount={Math.abs(unallocated) / 100}
                                    />
                                </dd>
                            </div>
                        </dl>
                        {overAllocated ? (
                            <p className="mt-2 text-xs text-destructive">
                                More has been applied than this {noun} carries.
                                Reduce the amounts, or raise the total.
                            </p>
                        ) : null}
                        <InputError message={form.errors.allocations} />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Notes</CardTitle>
                    <CardDescription>
                        Internal. Not shown to the counterparty.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <textarea
                        id="notes"
                        rows={3}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        placeholder="For whoever picks this up next."
                        className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                    />
                    <InputError message={form.errors.notes} />
                </CardContent>
            </Card>

            <div className="flex items-center gap-3">
                <Button
                    type="submit"
                    disabled={form.processing || overAllocated}
                >
                    {form.processing ? 'Saving…' : `Save draft ${noun}`}
                </Button>
                <Button asChild variant="ghost">
                    <Link
                        href={
                            paymentIndex({ query: { type: form.data.type } })
                                .url
                        }
                    >
                        Cancel
                    </Link>
                </Button>
            </div>
        </form>
    );
}
