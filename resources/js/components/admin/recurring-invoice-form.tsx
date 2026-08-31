import { Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';
import { CounterpartyPicker } from '@/components/admin/counterparty-picker';
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
import { DatePicker } from '@/components/ui/date-picker';
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
    amountInputToCentavos,
    centavosToAmountInput,
    formatAmountInput,
    isAmountInput,
} from '@/lib/money-input';
import {
    index as scheduleIndex,
    update as scheduleUpdate,
} from '@/routes/admin/recurring-invoices';
import type {
    RecurringFrequency,
    RecurringInvoiceEditable,
    RecurringInvoiceFormOptions,
    RecurringInvoiceLineDraft,
} from '@/types';
import { RECURRING_FREQUENCY_LABELS } from '@/types';

/**
 * Editor for a standing instruction.
 *
 * Close to the invoice form on purpose — a schedule is an invoice that has not
 * happened yet, and an operator should recognise it. Two differences are
 * deliberate:
 *
 * - No issue or due *date*, because there is no single one. A cadence and a
 *   day of the month, and a number of days to allow for payment.
 * - No totals panel. What a schedule will charge depends on the tax rates on
 *   the day it fires, so a figure shown here would be a guess presented as a
 *   fact. The per-line amounts are shown instead, which are known.
 */

/**
 * Edit only.
 *
 * A schedule is now set up on the invoice form, while the first invoice is
 * being raised — see `StartInvoiceSchedule`. This form changes one that
 * already exists, so there is no create branch left to take.
 */
type Mode = { kind: 'edit'; schedule: RecurringInvoiceEditable };

interface RecurringInvoiceFormProps extends RecurringInvoiceFormOptions {
    mode: Mode;
}

interface FormShape {
    name: string;
    type: 'sales';
    contact_id: number | null;
    lms_student_id: number | null;
    reference: string;
    is_vat_inclusive: boolean;
    notes: string;
    terms: string;
    frequency: RecurringFrequency;
    day_of_month: number;
    starts_on: string;
    ends_on: string;
    due_days: string;
    is_active: boolean;
    lines: RecurringInvoiceLineDraft[];
    [key: string]:
        | string
        | number
        | boolean
        | null
        | RecurringInvoiceLineDraft[];
}

function emptyLine(): RecurringInvoiceLineDraft {
    return {
        description: '',
        quantity: '1',
        unit_price_centavos: 0,
        account_id: null,
        tax_rate_id: null,
    };
}

function buildDefaults(mode: Mode): FormShape {
    const s = mode.schedule;

    return {
        name: s.name,
        type: 'sales',
        contact_id: s.contact_id,
        lms_student_id: s.lms_student_id,
        reference: s.reference ?? '',
        is_vat_inclusive: s.is_vat_inclusive,
        notes: s.notes ?? '',
        terms: s.terms ?? '',
        frequency: s.frequency,
        day_of_month: s.day_of_month,
        starts_on: s.starts_on,
        ends_on: s.ends_on ?? '',
        due_days: s.due_days === null ? '' : String(s.due_days),
        is_active: s.is_active,
        lines: s.lines.length > 0 ? s.lines : [emptyLine()],
    };
}

/** Quantity: signed, four places, matching decimal(12,4). */
const QUANTITY_PATTERN = /^-?\d*\.?\d{0,4}$/;

export function RecurringInvoiceForm({
    mode,
    contactOptions,
    accountOptions,
    taxRateOptions,
}: RecurringInvoiceFormProps) {
    const form = useForm<FormShape>(buildDefaults(mode));

    // The price boxes are driven by their own raw text, never re-derived from
    // the parsed centavos — deriving reformats on every keystroke and a
    // multi-digit figure becomes untypeable. Same rule as the invoice form.
    const [rawPrices, setRawPrices] = useState<string[]>(() =>
        form.data.lines.map((line) =>
            centavosToAmountInput(line.unit_price_centavos),
        ),
    );

    const updateLine = (
        index: number,
        patch: Partial<RecurringInvoiceLineDraft>,
    ) => {
        form.setData(
            'lines',
            form.data.lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );
    };

    const setPrice = (index: number, input: string) => {
        if (!isAmountInput(input)) {
            return;
        }

        setRawPrices((prev) => prev.map((v, i) => (i === index ? input : v)));
        updateLine(index, {
            unit_price_centavos: amountInputToCentavos(input),
        });
    };

    const addLine = () => {
        form.setData('lines', [...form.data.lines, emptyLine()]);
        setRawPrices((prev) => [...prev, '']);
    };

    const removeLine = (index: number) => {
        // A schedule needs at least one line, so the last one is emptied
        // rather than removed — a lineless form with no way back is a dead end.
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

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = {
            onError: () =>
                toast.error('Check the highlighted fields and try again.'),
        };

        form.put(
            scheduleUpdate({ recurringInvoice: mode.schedule.id }).url,
            options,
        );
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>What this schedule bills</CardTitle>
                    <CardDescription>
                        Every invoice it raises names this payer and carries
                        these lines.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={form.data.name}
                            placeholder="Grade 7 tuition — Dela Cruz"
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            aria-invalid={!!form.errors.name}
                        />
                        <p className="text-xs text-muted-foreground">
                            Only ever seen by staff, in this list.
                        </p>
                        <InputError message={form.errors.name} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="contact_id">Bill to</Label>
                        <CounterpartyPicker
                            id="contact_id"
                            noun="customer"
                            options={contactOptions}
                            value={form.data.contact_id}
                            disabled={contactOptions.length === 0}
                            onSelect={(contactId) =>
                                form.setData('contact_id', contactId)
                            }
                        />
                        <InputError message={form.errors.contact_id} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="reference">Reference</Label>
                        <Input
                            id="reference"
                            value={form.data.reference}
                            placeholder="Optional — copied onto every invoice"
                            onChange={(e) =>
                                form.setData('reference', e.target.value)
                            }
                        />
                        <InputError message={form.errors.reference} />
                    </div>

                    <div className="flex items-end gap-2 pb-2">
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
                        <Label
                            htmlFor="is_vat_inclusive"
                            className="font-normal"
                        >
                            Prices include VAT
                        </Label>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>When it runs</CardTitle>
                    <CardDescription>
                        A draft is raised overnight on the chosen day. Short
                        months are clamped to their last day, and the schedule
                        returns to the chosen day after.
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="space-y-2">
                        <Label htmlFor="frequency">Every</Label>
                        <Select
                            value={form.data.frequency}
                            onValueChange={(value) =>
                                form.setData(
                                    'frequency',
                                    value as RecurringFrequency,
                                )
                            }
                        >
                            <SelectTrigger id="frequency">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {(
                                    Object.keys(
                                        RECURRING_FREQUENCY_LABELS,
                                    ) as RecurringFrequency[]
                                ).map((value) => (
                                    <SelectItem key={value} value={value}>
                                        {RECURRING_FREQUENCY_LABELS[value]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.frequency} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="day_of_month">On day</Label>
                        <Input
                            id="day_of_month"
                            inputMode="numeric"
                            value={String(form.data.day_of_month)}
                            onChange={(e) =>
                                form.setData(
                                    'day_of_month',
                                    Number(
                                        e.target.value.replace(/\D/g, '') || 0,
                                    ),
                                )
                            }
                            aria-invalid={!!form.errors.day_of_month}
                        />
                        <InputError message={form.errors.day_of_month} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="starts_on">First invoice</Label>
                        <DatePicker
                            id="starts_on"
                            value={form.data.starts_on}
                            onChange={(value) =>
                                form.setData('starts_on', value)
                            }
                            ariaInvalid={!!form.errors.starts_on}
                        />
                        <InputError message={form.errors.starts_on} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="ends_on">Stop after</Label>
                        <DatePicker
                            id="ends_on"
                            value={form.data.ends_on}
                            onChange={(value) => form.setData('ends_on', value)}
                            placeholder="Runs until paused"
                            ariaInvalid={!!form.errors.ends_on}
                        />
                        <InputError message={form.errors.ends_on} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="due_days">Payable within</Label>
                        <div className="flex items-center gap-2">
                            <Input
                                id="due_days"
                                inputMode="numeric"
                                className="w-24"
                                value={form.data.due_days}
                                onChange={(e) =>
                                    form.setData(
                                        'due_days',
                                        e.target.value.replace(/\D/g, ''),
                                    )
                                }
                            />
                            <span className="text-sm text-muted-foreground">
                                days
                            </span>
                        </div>
                        <InputError message={form.errors.due_days} />
                    </div>

                    <div className="flex items-end gap-2 pb-2">
                        <Checkbox
                            id="is_active"
                            checked={form.data.is_active}
                            onCheckedChange={(checked) =>
                                form.setData('is_active', checked === true)
                            }
                        />
                        <Label htmlFor="is_active" className="font-normal">
                            Active
                        </Label>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Lines</CardTitle>
                    <CardDescription>
                        Copied onto each invoice. VAT is worked out when the
                        invoice is raised, from the rate in force that day.
                    </CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-[36%]">
                                        Description
                                    </TableHead>
                                    <TableHead className="w-[10%] text-right">
                                        Qty
                                    </TableHead>
                                    <TableHead className="w-[16%] text-right">
                                        Unit price
                                    </TableHead>
                                    <TableHead className="w-[18%]">
                                        Account
                                    </TableHead>
                                    <TableHead className="w-[14%]">
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
                                                placeholder="Tuition fee — Grade 7"
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
                                                onChange={(e) => {
                                                    if (
                                                        !QUANTITY_PATTERN.test(
                                                            e.target.value,
                                                        )
                                                    ) {
                                                        return;
                                                    }

                                                    updateLine(index, {
                                                        quantity:
                                                            e.target.value,
                                                    });
                                                }}
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
                                                onBlur={() =>
                                                    setRawPrices((prev) =>
                                                        prev.map((v, i) =>
                                                            i === index
                                                                ? formatAmountInput(
                                                                      v,
                                                                  )
                                                                : v,
                                                        ),
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
                                                    <SelectValue placeholder="Choose" />
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
                                                        ? 'none'
                                                        : String(
                                                              line.tax_rate_id,
                                                          )
                                                }
                                                onValueChange={(value) =>
                                                    updateLine(index, {
                                                        tax_rate_id:
                                                            value === 'none'
                                                                ? null
                                                                : Number(value),
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    aria-label={`Line ${index + 1} tax rate`}
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="none">
                                                        None
                                                    </SelectItem>
                                                    {taxRateOptions.map(
                                                        (rate) => (
                                                            <SelectItem
                                                                key={rate.id}
                                                                value={String(
                                                                    rate.id,
                                                                )}
                                                            >
                                                                {rate.code}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </TableCell>
                                        <TableCell>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    removeLine(index)
                                                }
                                                aria-label={`Remove line ${index + 1}`}
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <div className="flex items-center justify-between gap-2 px-4 py-3">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={addLine}
                        >
                            <Plus className="mr-1 h-4 w-4" />
                            Add line
                        </Button>
                        <p className="text-sm text-muted-foreground">
                            Lines total{' '}
                            <Money
                                amount={
                                    form.data.lines.reduce(
                                        (sum, l) =>
                                            sum +
                                            l.unit_price_centavos *
                                                Number(l.quantity || 0),
                                        0,
                                    ) / 100
                                }
                            />{' '}
                            before VAT.
                        </p>
                    </div>
                    <InputError message={form.errors.lines} />
                </CardContent>
            </Card>

            <div className="flex items-center gap-2">
                <Button
                    type="submit"
                    disabled={form.processing || !form.isDirty}
                >
                    {'Save changes'}
                </Button>
                <Button asChild variant="outline">
                    <Link href={scheduleIndex().url}>Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
