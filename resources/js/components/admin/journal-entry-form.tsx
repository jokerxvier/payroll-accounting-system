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
    index as journalIndex,
    store as journalStore,
    update as journalUpdate,
} from '@/routes/admin/journal-entries';
import type {
    JournalAccountOption,
    JournalEntryEditable,
    JournalEntryLineDraft,
} from '@/types';

/**
 * Debit/credit line editor for a journal entry.
 *
 * Laid out as the two-column ledger table from THEME.md §6.3 — debit and
 * credit each right-aligned in their own column, account codes in mono —
 * because that is how an accountant reads down a page checking that the
 * sides agree.
 *
 * The running totals and the difference are the point of the footer. The
 * server refuses an unbalanced entry, but discovering that on submit means
 * hunting for the wrong figure afterwards; showing the difference as it
 * changes turns it into a self-correcting form.
 */

type Mode = { kind: 'create' } | { kind: 'edit'; entry: JournalEntryEditable };

interface JournalEntryFormProps {
    mode: Mode;
    accountOptions: JournalAccountOption[];
}

interface FormShape {
    date: string;
    reference: string;
    narration: string;
    lines: JournalEntryLineDraft[];
    [key: string]: string | JournalEntryLineDraft[];
}

/** A journal entry needs at least two lines, so a new one starts with two. */
function emptyLine(): JournalEntryLineDraft {
    return {
        account_id: null,
        debit_centavos: 0,
        credit_centavos: 0,
        description: '',
    };
}

function buildDefaults(mode: Mode): FormShape {
    if (mode.kind === 'create') {
        return {
            date: new Date().toISOString().slice(0, 10),
            reference: '',
            narration: '',
            lines: [emptyLine(), emptyLine()],
        };
    }

    const entry = mode.entry;

    return {
        date: entry.date,
        reference: entry.reference ?? '',
        narration: entry.narration ?? '',
        lines:
            entry.lines.length > 0 ? entry.lines : [emptyLine(), emptyLine()],
    };
}

function centavosToPesos(centavos: number): string {
    return centavos === 0 ? '' : (centavos / 100).toFixed(2);
}

function pesosToCentavos(input: string): number {
    const cleaned = input.trim();

    if (cleaned === '') {
        return 0;
    }

    const parsed = Number(cleaned);

    if (Number.isNaN(parsed) || parsed < 0) {
        return 0;
    }

    return Math.round(parsed * 100);
}

/**
 * Digits, at most one decimal point, at most two places after it.
 *
 * Applied as a gate on each keystroke rather than sanitising afterwards, so
 * a rejected character simply never appears. That also covers the minus
 * sign: a line moves one side by a positive amount, so a negative is not a
 * value to correct later, it is a keystroke to refuse now.
 *
 * A trailing point ("1234.") passes — it is a legitimate half-finished
 * entry, and refusing it would eat the key the moment it was pressed.
 */
const AMOUNT_PATTERN = /^\d*\.?\d{0,2}$/;

/** The raw text sitting in one line's two amount inputs. */
interface RawAmounts {
    debit: string;
    credit: string;
}

function rawFor(line: JournalEntryLineDraft): RawAmounts {
    return {
        debit: centavosToPesos(line.debit_centavos),
        credit: centavosToPesos(line.credit_centavos),
    };
}

export function JournalEntryForm({
    mode,
    accountOptions,
}: JournalEntryFormProps) {
    const form = useForm<FormShape>(buildDefaults(mode));
    const isEdit = mode.kind === 'edit';

    // The amount inputs are driven by their own raw text, not by the parsed
    // centavos. Deriving the displayed value from the parsed number means
    // every keystroke reformats it — typing "5" becomes "5.00" and the next
    // character lands after the decimals — so a multi-digit figure cannot be
    // typed at all. Same reason allowance-form.tsx keeps local string state.
    const [raw, setRaw] = useState<RawAmounts[]>(() =>
        form.data.lines.map(rawFor),
    );

    const { totalDebit, totalCredit, difference } = useMemo(() => {
        const debit = form.data.lines.reduce(
            (sum, l) => sum + l.debit_centavos,
            0,
        );
        const credit = form.data.lines.reduce(
            (sum, l) => sum + l.credit_centavos,
            0,
        );

        return {
            totalDebit: debit,
            totalCredit: credit,
            difference: debit - credit,
        };
    }, [form.data.lines]);

    const isBalanced = difference === 0 && totalDebit > 0;

    const updateLine = (
        index: number,
        patch: Partial<JournalEntryLineDraft>,
    ): void => {
        form.setData(
            'lines',
            form.data.lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );
    };

    /**
     * Handle a keystroke in one of a line's two amount inputs.
     *
     * Keeps the raw text and the parsed centavos in step, and clears the
     * opposite side once this one carries a figure — a line moves exactly
     * one side, and the server rejects both being set.
     */
    const setAmount = (
        index: number,
        side: 'debit' | 'credit',
        input: string,
    ): void => {
        if (!AMOUNT_PATTERN.test(input)) {
            return;
        }

        const centavos = pesosToCentavos(input);
        const clearsOther = centavos > 0;

        setRaw((prev) =>
            prev.map((entry, i) => {
                if (i !== index) {
                    return entry;
                }

                if (side === 'debit') {
                    return {
                        debit: input,
                        credit: clearsOther ? '' : entry.credit,
                    };
                }

                return {
                    debit: clearsOther ? '' : entry.debit,
                    credit: input,
                };
            }),
        );

        updateLine(
            index,
            side === 'debit'
                ? {
                      debit_centavos: centavos,
                      ...(clearsOther ? { credit_centavos: 0 } : {}),
                  }
                : {
                      credit_centavos: centavos,
                      ...(clearsOther ? { debit_centavos: 0 } : {}),
                  },
        );
    };

    /** Settle a half-finished figure ("1234.") into "1234.00" once focus leaves. */
    const normaliseAmount = (index: number, side: 'debit' | 'credit'): void => {
        const line = form.data.lines[index];

        if (line === undefined) {
            return;
        }

        const centavos =
            side === 'debit' ? line.debit_centavos : line.credit_centavos;

        setRaw((prev) =>
            prev.map((entry, i) =>
                i === index
                    ? { ...entry, [side]: centavosToPesos(centavos) }
                    : entry,
            ),
        );
    };

    const addLine = (): void => {
        form.setData('lines', [...form.data.lines, emptyLine()]);
        setRaw((prev) => [...prev, { debit: '', credit: '' }]);
    };

    const removeLine = (index: number): void => {
        // Never drop below the two lines double-entry requires.
        if (form.data.lines.length <= 2) {
            return;
        }

        form.setData(
            'lines',
            form.data.lines.filter((_, i) => i !== index),
        );
        setRaw((prev) => prev.filter((_, i) => i !== index));
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (mode.kind === 'create') {
            form.post(journalStore().url, {
                onSuccess: () => toast.success('Draft journal entry saved.'),
            });

            return;
        }

        form.patch(journalUpdate({ journalEntry: mode.entry.id }).url, {
            onSuccess: () => toast.success('Draft journal entry updated.'),
        });
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6" noValidate>
            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Entry
                    </CardTitle>
                    <CardDescription>
                        The date decides which accounting period the entry posts
                        into. It must fall in an open one.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-[12rem_1fr]">
                        <div className="grid gap-2">
                            <Label htmlFor="je-date">Date</Label>
                            <Input
                                id="je-date"
                                type="date"
                                value={form.data.date}
                                onChange={(e) =>
                                    form.setData('date', e.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.date} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="je-reference">
                                Reference (optional)
                            </Label>
                            <Input
                                id="je-reference"
                                type="text"
                                maxLength={64}
                                value={form.data.reference}
                                onChange={(e) =>
                                    form.setData('reference', e.target.value)
                                }
                                placeholder="Cheque number, document id, …"
                                className="font-mono text-sm"
                            />
                            <InputError message={form.errors.reference} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="je-narration">Narration</Label>
                        <textarea
                            id="je-narration"
                            rows={2}
                            value={form.data.narration}
                            onChange={(e) =>
                                form.setData('narration', e.target.value)
                            }
                            placeholder="What this entry records, in one line."
                            className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                        />
                        <InputError message={form.errors.narration} />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="font-serif text-base">
                        Lines
                    </CardTitle>
                    <CardDescription>
                        Each line carries a debit or a credit, never both.
                        Totals must agree before the entry can post.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3 p-0 sm:p-0">
                    <div className="overflow-x-auto">
                        <Table className="text-sm">
                            <TableHeader>
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className="w-[3rem] text-xs tracking-wide text-muted-foreground uppercase">
                                        #
                                    </TableHead>
                                    <TableHead className="min-w-[16rem] text-xs tracking-wide text-muted-foreground uppercase">
                                        Account
                                    </TableHead>
                                    <TableHead className="min-w-[12rem] text-xs tracking-wide text-muted-foreground uppercase">
                                        Memo
                                    </TableHead>
                                    <TableHead className="w-[9rem] text-right text-xs tracking-wide text-muted-foreground uppercase">
                                        Debit
                                    </TableHead>
                                    <TableHead className="w-[9rem] text-right text-xs tracking-wide text-muted-foreground uppercase">
                                        Credit
                                    </TableHead>
                                    <TableHead className="sr-only w-[3rem] text-right">
                                        Remove
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {form.data.lines.map((line, index) => (
                                    <TableRow key={index} className="align-top">
                                        <TableCell className="pt-4 font-mono text-xs text-muted-foreground">
                                            {index + 1}
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
                                                    aria-label={`Account for line ${index + 1}`}
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Select an account" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {accountOptions.map(
                                                        (option) => (
                                                            <SelectItem
                                                                key={option.id}
                                                                value={String(
                                                                    option.id,
                                                                )}
                                                            >
                                                                {option.code} —{' '}
                                                                {option.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    form.errors[
                                                        `lines.${index}.account_id` as keyof typeof form.errors
                                                    ] as string | undefined
                                                }
                                            />
                                        </TableCell>

                                        <TableCell>
                                            <Input
                                                aria-label={`Memo for line ${index + 1}`}
                                                type="text"
                                                maxLength={255}
                                                value={line.description}
                                                onChange={(e) =>
                                                    updateLine(index, {
                                                        description:
                                                            e.target.value,
                                                    })
                                                }
                                                placeholder="Optional"
                                            />
                                        </TableCell>

                                        <TableCell>
                                            <Input
                                                aria-label={`Debit for line ${index + 1}`}
                                                inputMode="decimal"
                                                value={raw[index]?.debit ?? ''}
                                                onChange={(e) =>
                                                    setAmount(
                                                        index,
                                                        'debit',
                                                        e.target.value,
                                                    )
                                                }
                                                onBlur={() =>
                                                    normaliseAmount(
                                                        index,
                                                        'debit',
                                                    )
                                                }
                                                placeholder="0.00"
                                                className="text-right tabular-nums"
                                            />
                                        </TableCell>

                                        <TableCell>
                                            <Input
                                                aria-label={`Credit for line ${index + 1}`}
                                                inputMode="decimal"
                                                value={raw[index]?.credit ?? ''}
                                                onChange={(e) =>
                                                    setAmount(
                                                        index,
                                                        'credit',
                                                        e.target.value,
                                                    )
                                                }
                                                onBlur={() =>
                                                    normaliseAmount(
                                                        index,
                                                        'credit',
                                                    )
                                                }
                                                placeholder="0.00"
                                                className="text-right tabular-nums"
                                            />
                                        </TableCell>

                                        <TableCell className="pt-3 text-right">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                aria-label={`Remove line ${index + 1}`}
                                                disabled={
                                                    form.data.lines.length <= 2
                                                }
                                                className="text-destructive hover:bg-destructive/10 hover:text-destructive"
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

                    <div className="flex flex-wrap items-center justify-between gap-4 border-t px-4 py-3">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={addLine}
                        >
                            <Plus className="mr-1 h-4 w-4" />
                            Add line
                        </Button>

                        <BalanceSummary
                            totalDebit={totalDebit}
                            totalCredit={totalCredit}
                            difference={difference}
                            isBalanced={isBalanced}
                        />
                    </div>

                    <div className="px-4 pb-4">
                        <InputError
                            message={form.errors.lines as string | undefined}
                        />
                    </div>
                </CardContent>
            </Card>

            <div className="flex items-center justify-end gap-2">
                <Button asChild variant="outline" type="button">
                    <Link href={journalIndex().url}>Cancel</Link>
                </Button>
                <Button
                    type="submit"
                    disabled={form.processing || (isEdit && !form.isDirty)}
                >
                    {form.processing
                        ? 'Saving…'
                        : isEdit
                          ? 'Save draft'
                          : 'Save draft'}
                </Button>
            </div>
        </form>
    );
}

/**
 * Running totals and the gap between them.
 *
 * The difference is the useful number: it is what tells the operator how far
 * out they are and in which direction, which is usually enough to spot the
 * line they mistyped without checking every row.
 */
function BalanceSummary({
    totalDebit,
    totalCredit,
    difference,
    isBalanced,
}: {
    totalDebit: number;
    totalCredit: number;
    difference: number;
    isBalanced: boolean;
}) {
    return (
        <div className="flex flex-wrap items-center gap-6 text-sm">
            <div className="flex items-center gap-2">
                <span className="text-xs tracking-wide text-muted-foreground uppercase">
                    Debits
                </span>
                <Money amount={totalDebit / 100} className="tabular-nums" />
            </div>
            <div className="flex items-center gap-2">
                <span className="text-xs tracking-wide text-muted-foreground uppercase">
                    Credits
                </span>
                <Money amount={totalCredit / 100} className="tabular-nums" />
            </div>
            <div
                className={cn(
                    'flex items-center gap-2 rounded-md px-2 py-1',
                    isBalanced
                        ? 'bg-success/15 text-success'
                        : 'bg-destructive/10 text-destructive',
                )}
            >
                <span className="text-xs font-medium tracking-wide uppercase">
                    {isBalanced ? 'Balanced' : 'Out by'}
                </span>
                {isBalanced ? null : (
                    <Money
                        amount={Math.abs(difference) / 100}
                        className="tabular-nums"
                    />
                )}
            </div>
        </div>
    );
}
