import { Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
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
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    index as invoiceIndex,
    store as invoiceStore,
    update as invoiceUpdate,
} from '@/routes/admin/invoices';
import type {
    InvoiceEditable,
    InvoiceFormOptions,
    InvoiceLineDraft,
    InvoiceTaxRateOption,
    InvoiceType,
} from '@/types';

/**
 * Line editor for an invoice or a bill.
 *
 * The totals panel shows the three BIR sales buckets separately rather than
 * one subtotal, because that is the shape of the document being produced —
 * an operator adding an exempt line should watch it land in VAT-Exempt
 * Sales, not disappear into an aggregate.
 *
 * Those figures are a preview and say so. The server recomputes from the
 * lines on every save and again at approval, so this arithmetic is never
 * what gets stored; it exists so the operator can see the shape of the
 * document before committing to it.
 */

type Mode =
    | { kind: 'create'; type: InvoiceType }
    | { kind: 'edit'; invoice: InvoiceEditable };

interface InvoiceFormProps extends InvoiceFormOptions {
    mode: Mode;
}

interface FormShape {
    type: InvoiceType;
    contact_id: number | null;
    reference: string;
    issue_date: string;
    due_date: string;
    is_vat_inclusive: boolean;
    notes: string;
    terms: string;
    lines: InvoiceLineDraft[];
    [key: string]: string | number | boolean | null | InvoiceLineDraft[];
}

function emptyLine(): InvoiceLineDraft {
    return {
        description: '',
        quantity: '1',
        unit_price_centavos: 0,
        account_id: null,
        tax_rate_id: null,
    };
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function buildDefaults(mode: Mode): FormShape {
    if (mode.kind === 'create') {
        return {
            type: mode.type,
            contact_id: null,
            reference: '',
            issue_date: today(),
            due_date: '',
            is_vat_inclusive: false,
            notes: '',
            terms: '',
            lines: [emptyLine()],
        };
    }

    const invoice = mode.invoice;

    return {
        type: invoice.type,
        contact_id: invoice.contact_id,
        reference: invoice.reference ?? '',
        issue_date: invoice.issue_date,
        due_date: invoice.due_date ?? '',
        is_vat_inclusive: invoice.is_vat_inclusive,
        notes: invoice.notes ?? '',
        terms: invoice.terms ?? '',
        lines: invoice.lines.length > 0 ? invoice.lines : [emptyLine()],
    };
}

/**
 * Digits, at most one decimal point, at most two places after it.
 *
 * Applied as a gate on each keystroke rather than sanitising afterwards, so
 * a rejected character never appears. A trailing point ("1234.") passes — it
 * is a legitimate half-finished entry, and refusing it would eat the key the
 * moment it was pressed.
 */
const PRICE_PATTERN = /^\d*\.?\d{0,2}$/;

/** Same, but signed and to four places, matching decimal(12,4). */
const QUANTITY_PATTERN = /^-?\d*\.?\d{0,4}$/;

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

function quantityToNumber(input: string): number {
    const parsed = Number(input.trim());

    return Number.isNaN(parsed) ? 0 : parsed;
}

/**
 * Which sales bucket a line falls in, mirroring
 * InvoiceTotalsCalculator::bucketFor(). A line with no rate is treated as
 * exempt, and a VAT rate at 0 bps stays in VATable — the preview must agree
 * with the server rather than quietly presenting a tidier answer.
 */
function bucketFor(rate: InvoiceTaxRateOption | undefined): string {
    if (rate === undefined) {
        return 'exempt';
    }

    if (rate.type === 'exempt' || rate.type === 'zero_rated') {
        return rate.type;
    }

    return 'vatable';
}

export function InvoiceForm({
    mode,
    contactOptions,
    accountOptions,
    taxRateOptions,
    nextNumber,
}: InvoiceFormProps) {
    const form = useForm<FormShape>(buildDefaults(mode));
    const isEdit = mode.kind === 'edit';
    const isSales = form.data.type === 'sales';

    // The price inputs are driven by their own raw text, not by the parsed
    // centavos. Deriving the displayed value from the parsed number means
    // every keystroke reformats it — typing "5" becomes "5.00" and the next
    // character lands after the decimals — so a multi-digit figure cannot be
    // typed at all. Same fix journal-entry-form.tsx carries, and the reason
    // its tests now drive input character by character.
    const [rawPrices, setRawPrices] = useState<string[]>(() =>
        form.data.lines.map((line) =>
            centavosToPesos(line.unit_price_centavos),
        ),
    );

    const rateById = useMemo(() => {
        const map = new Map<number, InvoiceTaxRateOption>();
        taxRateOptions.forEach((rate) => map.set(rate.id, rate));

        return map;
    }, [taxRateOptions]);

    /**
     * A preview of the document face. Mirrors InvoiceTotalsCalculator:
     * rounding happens once per line, never on the total, so what is shown
     * here matches what the server will store to the centavo.
     */
    const totals = useMemo(() => {
        let vatable = 0;
        let exempt = 0;
        let zeroRated = 0;
        let vat = 0;

        form.data.lines.forEach((line) => {
            const rate =
                line.tax_rate_id === null
                    ? undefined
                    : rateById.get(line.tax_rate_id);

            const extended = Math.round(
                line.unit_price_centavos * quantityToNumber(line.quantity),
            );

            const postsTax =
                rate !== undefined &&
                rate.rate_bps > 0 &&
                (rate.type === 'vat_sales' || rate.type === 'vat_purchase');

            let net = extended;
            let tax = 0;

            if (postsTax) {
                if (form.data.is_vat_inclusive) {
                    tax = Math.round(
                        (extended * rate.rate_bps) / (10000 + rate.rate_bps),
                    );
                    net = extended - tax;
                } else {
                    tax = Math.round((extended * rate.rate_bps) / 10000);
                }
            }

            const bucket = bucketFor(rate);

            if (bucket === 'exempt') {
                exempt += net;
            } else if (bucket === 'zero_rated') {
                zeroRated += net;
            } else {
                vatable += net;
            }

            vat += tax;
        });

        return {
            vatable,
            exempt,
            zeroRated,
            vat,
            total: vatable + exempt + zeroRated + vat,
        };
    }, [form.data.lines, form.data.is_vat_inclusive, rateById]);

    const updateLine = (
        index: number,
        patch: Partial<InvoiceLineDraft>,
    ): void => {
        form.setData(
            'lines',
            form.data.lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );
    };

    const setPrice = (index: number, input: string): void => {
        if (!PRICE_PATTERN.test(input)) {
            return;
        }

        setRawPrices((prev) =>
            prev.map((value, i) => (i === index ? input : value)),
        );
        updateLine(index, { unit_price_centavos: pesosToCentavos(input) });
    };

    const setQuantity = (index: number, input: string): void => {
        if (!QUANTITY_PATTERN.test(input)) {
            return;
        }

        updateLine(index, { quantity: input });
    };

    const addLine = (): void => {
        form.setData('lines', [...form.data.lines, emptyLine()]);
        setRawPrices((prev) => [...prev, '']);
    };

    const removeLine = (index: number): void => {
        // An invoice needs at least one line, so the last one is emptied
        // rather than removed — leaving a lineless form with no way to add
        // the first line back would be a dead end.
        if (form.data.lines.length === 1) {
            form.setData('lines', [emptyLine()]);
            setRawPrices(['']);

            return;
        }

        form.setData(
            'lines',
            form.data.lines.filter((_, i) => i !== index),
        );
        setRawPrices((prev) => prev.filter((_, i) => i !== index));
    };

    const handleSubmit = (event: FormEvent): void => {
        event.preventDefault();

        const options = {
            onError: () => {
                toast.error('Check the highlighted fields and try again.');
            },
        };

        if (isEdit) {
            form.put(invoiceUpdate({ invoice: mode.invoice.id }).url, options);

            return;
        }

        form.post(invoiceStore().url, options);
    };

    const documentNoun = isSales ? 'invoice' : 'bill';

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Details</CardTitle>
                    <CardDescription>
                        {nextNumber === null
                            ? `No numbering series is set up for this document type yet, so approving will fail until one exists.`
                            : `This ${documentNoun} takes number ${nextNumber} when it is approved. Drafts are not numbered.`}
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="contact_id">
                            {isSales ? 'Customer' : 'Supplier'}
                        </Label>
                        <Select
                            value={
                                form.data.contact_id === null
                                    ? ''
                                    : String(form.data.contact_id)
                            }
                            onValueChange={(value) =>
                                form.setData('contact_id', Number(value))
                            }
                        >
                            <SelectTrigger id="contact_id">
                                <SelectValue
                                    placeholder={`Choose a ${isSales ? 'customer' : 'supplier'}`}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {contactOptions.map((contact) => (
                                    <SelectItem
                                        key={contact.id}
                                        value={String(contact.id)}
                                    >
                                        {contact.name}
                                        {contact.tin
                                            ? ` · TIN ${contact.tin}`
                                            : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.contact_id} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="reference">Reference</Label>
                        <Input
                            id="reference"
                            value={form.data.reference}
                            maxLength={64}
                            onChange={(e) =>
                                form.setData('reference', e.target.value)
                            }
                            placeholder={
                                isSales
                                    ? 'Student or PO reference'
                                    : "The supplier's own document number"
                            }
                        />
                        <InputError message={form.errors.reference} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="issue_date">Issue date</Label>
                        <Input
                            id="issue_date"
                            type="date"
                            value={form.data.issue_date}
                            onChange={(e) =>
                                form.setData('issue_date', e.target.value)
                            }
                        />
                        <InputError message={form.errors.issue_date} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="due_date">Due date</Label>
                        <Input
                            id="due_date"
                            type="date"
                            value={form.data.due_date}
                            onChange={(e) =>
                                form.setData('due_date', e.target.value)
                            }
                        />
                        <InputError message={form.errors.due_date} />
                    </div>

                    <div className="flex items-start gap-3 sm:col-span-2">
                        <Checkbox
                            id="is_vat_inclusive"
                            checked={form.data.is_vat_inclusive}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'is_vat_inclusive',
                                    checked === true,
                                )
                            }
                        />
                        <div className="space-y-1">
                            <Label
                                htmlFor="is_vat_inclusive"
                                className="font-normal"
                            >
                                Prices include VAT
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Tick this when the prices entered are what the
                                customer pays. The VAT is extracted from them
                                rather than added on top — the totals come out
                                the same either way.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Lines</CardTitle>
                    <CardDescription>
                        Each line posts its net to the account you choose. VAT
                        goes to the tax account, never to income.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4 p-0 sm:p-0">
                    <div className="overflow-x-auto">
                        <Table className="text-sm">
                            <TableHeader>
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Description
                                    </TableHead>
                                    <TableHead className="w-[6rem] text-right text-xs tracking-wide text-muted-foreground uppercase">
                                        Qty
                                    </TableHead>
                                    <TableHead className="w-[9rem] text-right text-xs tracking-wide text-muted-foreground uppercase">
                                        Unit price
                                    </TableHead>
                                    <TableHead className="w-[13rem] text-xs tracking-wide text-muted-foreground uppercase">
                                        {isSales
                                            ? 'Income account'
                                            : 'Expense account'}
                                    </TableHead>
                                    <TableHead className="w-[10rem] text-xs tracking-wide text-muted-foreground uppercase">
                                        VAT
                                    </TableHead>
                                    <TableHead className="sr-only">
                                        Remove
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {form.data.lines.map((line, index) => (
                                    <TableRow key={index}>
                                        <TableCell>
                                            <Input
                                                value={line.description}
                                                maxLength={255}
                                                aria-label={`Line ${index + 1} description`}
                                                onChange={(e) =>
                                                    updateLine(index, {
                                                        description:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={
                                                    form.errors[
                                                        `lines.${index}.description`
                                                    ]
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                value={line.quantity}
                                                inputMode="decimal"
                                                className="text-right tabular-nums"
                                                aria-label={`Line ${index + 1} quantity`}
                                                onChange={(e) =>
                                                    setQuantity(
                                                        index,
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    form.errors[
                                                        `lines.${index}.quantity`
                                                    ]
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                value={rawPrices[index] ?? ''}
                                                inputMode="decimal"
                                                placeholder="0.00"
                                                className="text-right tabular-nums"
                                                aria-label={`Line ${index + 1} unit price`}
                                                onChange={(e) =>
                                                    setPrice(
                                                        index,
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    form.errors[
                                                        `lines.${index}.unit_price_centavos`
                                                    ]
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                value={
                                                    line.account_id === null
                                                        ? ''
                                                        : String(
                                                              line.account_id,
                                                          )
                                                }
                                                onValueChange={(value) =>
                                                    updateLine(index, {
                                                        account_id:
                                                            Number(value),
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    aria-label={`Line ${index + 1} account`}
                                                >
                                                    <SelectValue placeholder="Account" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {accountOptions.map(
                                                        (account) => (
                                                            <SelectItem
                                                                key={account.id}
                                                                value={String(
                                                                    account.id,
                                                                )}
                                                            >
                                                                {account.code} ·{' '}
                                                                {account.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    form.errors[
                                                        `lines.${index}.account_id`
                                                    ]
                                                }
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Select
                                                value={
                                                    line.tax_rate_id === null
                                                        ? ''
                                                        : String(
                                                              line.tax_rate_id,
                                                          )
                                                }
                                                onValueChange={(value) =>
                                                    updateLine(index, {
                                                        tax_rate_id:
                                                            Number(value),
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    aria-label={`Line ${index + 1} VAT treatment`}
                                                >
                                                    <SelectValue placeholder="None" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {taxRateOptions.map(
                                                        (rate) => (
                                                            <SelectItem
                                                                key={rate.id}
                                                                value={String(
                                                                    rate.id,
                                                                )}
                                                            >
                                                                {rate.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                aria-label={`Remove line ${index + 1}`}
                                                onClick={() =>
                                                    removeLine(index)
                                                }
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <div className="px-4 pb-4">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={addLine}
                        >
                            <Plus className="mr-1 h-4 w-4" />
                            Add line
                        </Button>
                        <InputError message={form.errors.lines} />
                    </div>
                </CardContent>
            </Card>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Notes</CardTitle>
                        <CardDescription>
                            Terms print on the document. Notes stay internal.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="terms">Terms</Label>
                            <textarea
                                id="terms"
                                rows={3}
                                value={form.data.terms}
                                onChange={(e) =>
                                    form.setData('terms', e.target.value)
                                }
                                placeholder="Printed on the document — payment terms, bank details, anything the customer needs."
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputError message={form.errors.terms} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="notes">Internal notes</Label>
                            <textarea
                                id="notes"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                placeholder="Not printed. For whoever picks this up next."
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputError message={form.errors.notes} />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Preview</CardTitle>
                        <CardDescription>
                            Recalculated on save, and again when the document is
                            approved.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-2 text-sm">
                            <TotalRow
                                label="VATable sales"
                                centavos={totals.vatable}
                            />
                            <TotalRow
                                label="VAT-exempt sales"
                                centavos={totals.exempt}
                            />
                            <TotalRow
                                label="Zero-rated sales"
                                centavos={totals.zeroRated}
                            />
                            <TotalRow label="VAT" centavos={totals.vat} />
                            <div className="flex items-center justify-between border-t pt-2 text-base font-medium">
                                <dt>Total</dt>
                                <dd>
                                    <Money amount={totals.total / 100} />
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {form.processing
                        ? 'Saving…'
                        : isEdit
                          ? 'Save draft'
                          : `Save draft ${documentNoun}`}
                </Button>
                <Button asChild variant="ghost">
                    <Link
                        href={
                            invoiceIndex({
                                query: { type: form.data.type },
                            }).url
                        }
                    >
                        Cancel
                    </Link>
                </Button>
            </div>
        </form>
    );
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
