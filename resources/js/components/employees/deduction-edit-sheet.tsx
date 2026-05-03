import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import {
    store as deductionStore,
    update as deductionUpdate,
} from '@/actions/App/Http/Controllers/Employees/EmployeeDeductionController';
import { SCHEDULE_LABEL } from '@/components/employees/schedule-label';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type {
    DeductionTypeRef,
    EmployeeDeductionRow,
    SubscriptionSchedule,
} from '@/types';

/**
 * Create / edit a per-employee deduction subscription (Week 7, Chunk 6).
 *
 * The amount-vs-percent input switches based on the resolved DeductionType's
 * `calc_method` — mirrors the FormRequest's cross-field rule. When the user
 * picks a different type mid-edit we clear whichever field the new method
 * does not need so the request payload matches the FormRequest contract.
 */

interface DeductionEditSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lmsStaffId: number;
    /** Undefined → create mode. Provided → edit mode for that row. */
    deduction?: EmployeeDeductionRow;
    deductionTypeOptions: DeductionTypeRef[];
}

interface FormShape {
    deduction_type_id: number | '';
    amount_centavos: number | null;
    percent_basis_points: number | null;
    schedule: SubscriptionSchedule;
    effective_from: string;
    effective_to: string;
    notes: string;
    [key: string]: string | number | null;
}

const SCHEDULE_OPTIONS: SubscriptionSchedule[] = [
    'every_run',
    'first_half',
    'second_half',
    'monthly_first',
    'monthly_last',
];

function buildDefaults(deduction?: EmployeeDeductionRow): FormShape {
    if (deduction === undefined) {
        return {
            deduction_type_id: '',
            amount_centavos: null,
            percent_basis_points: null,
            schedule: 'every_run',
            effective_from: '',
            effective_to: '',
            notes: '',
        };
    }

    return {
        deduction_type_id: deduction.deduction_type.id,
        amount_centavos: deduction.amount_centavos,
        percent_basis_points: deduction.percent_basis_points,
        schedule: deduction.schedule,
        effective_from: deduction.effective_from,
        effective_to: deduction.effective_to ?? '',
        notes: deduction.notes ?? '',
    };
}

function centavosToPesos(centavos: number | null): string {
    if (centavos === null) {
        return '';
    }

    return (centavos / 100).toFixed(2);
}

function pesosToCentavosOrNull(input: string): number | null {
    const cleaned = input.trim();

    if (cleaned === '') {
        return null;
    }

    const parsed = Number(cleaned);

    if (Number.isNaN(parsed) || parsed < 0) {
        return null;
    }

    return Math.round(parsed * 100);
}

function basisPointsFromPercent(input: string): number | null {
    const cleaned = input.trim();

    if (cleaned === '') {
        return null;
    }

    const parsed = Number(cleaned);

    if (Number.isNaN(parsed) || parsed < 0) {
        return null;
    }

    return Math.round(parsed * 100);
}

function percentFromBasisPoints(bp: number | null): string {
    if (bp === null) {
        return '';
    }

    return (bp / 100).toFixed(2);
}

export function DeductionEditSheet({
    open,
    onOpenChange,
    lmsStaffId,
    deduction,
    deductionTypeOptions,
}: DeductionEditSheetProps) {
    const isEdit = deduction !== undefined;
    const form = useForm<FormShape>(buildDefaults(deduction));

    const [amountStr, setAmountStr] = useState(() =>
        centavosToPesos(form.data.amount_centavos),
    );
    const [percentStr, setPercentStr] = useState(() =>
        percentFromBasisPoints(form.data.percent_basis_points),
    );

    // Re-seed defaults when the parent swaps the row mid-life (e.g. clicking
    // Edit on a different row without closing the sheet first).
    const rowKey = deduction ? `edit:${deduction.id}` : 'create';

    useEffect(() => {
        const next = buildDefaults(deduction);
        form.setDefaults(next);
        form.setData(next);
        form.clearErrors();
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setAmountStr(centavosToPesos(next.amount_centavos));

        setPercentStr(percentFromBasisPoints(next.percent_basis_points));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rowKey]);

    const selectedType = deductionTypeOptions.find(
        (t) => t.id === form.data.deduction_type_id,
    );

    // When the type changes, clear whichever amount/percent field the new
    // calc_method does not use so the FormRequest's "must be empty" rule
    // is satisfied.
    const handleTypeChange = (rawValue: string) => {
        const id = Number(rawValue);
        const next = deductionTypeOptions.find((t) => t.id === id);

        form.setData('deduction_type_id', id);

        if (next?.calc_method === 'fixed') {
            form.setData('percent_basis_points', null);

            setPercentStr('');
        } else if (next?.calc_method === 'percent') {
            form.setData('amount_centavos', null);

            setAmountStr('');
        }
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        const onSuccess = () => {
            toast.success(isEdit ? 'Deduction updated.' : 'Deduction added.');
            onOpenChange(false);
        };

        if (isEdit && deduction) {
            form.patch(
                deductionUpdate({
                    staffId: lmsStaffId,
                    employeeDeduction: deduction.id,
                }).url,
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess,
                },
            );

            return;
        }

        form.post(deductionStore({ staffId: lmsStaffId }).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess,
        });
    };

    const handleCancel = () => {
        form.reset();
        form.clearErrors();
        onOpenChange(false);
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col gap-0 p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b">
                    <SheetTitle className="font-serif text-lg">
                        {isEdit ? 'Edit deduction' : 'Add deduction'}
                    </SheetTitle>
                    <SheetDescription>
                        Custom deductions apply on top of statutory
                        contributions.
                    </SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={handleSubmit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <div className="flex-1 space-y-4 overflow-y-auto p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="deduction_type_id">
                                Deduction type
                            </Label>
                            <Select
                                value={
                                    form.data.deduction_type_id === ''
                                        ? ''
                                        : String(form.data.deduction_type_id)
                                }
                                onValueChange={handleTypeChange}
                            >
                                <SelectTrigger
                                    id="deduction_type_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Select a type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {deductionTypeOptions.map((opt) => (
                                        <SelectItem
                                            key={opt.id}
                                            value={String(opt.id)}
                                        >
                                            <span>{opt.name}</span>
                                            <span className="ml-2 font-mono text-xs text-muted-foreground">
                                                {opt.code}
                                            </span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={form.errors.deduction_type_id}
                            />
                        </div>

                        {selectedType?.calc_method === 'fixed' && (
                            <div className="grid gap-2">
                                <Label htmlFor="amount_centavos">Amount</Label>
                                <div className="relative">
                                    <span
                                        aria-hidden="true"
                                        className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                                    >
                                        ₱
                                    </span>
                                    <Input
                                        id="amount_centavos"
                                        inputMode="decimal"
                                        pattern="[0-9]*\.?[0-9]*"
                                        value={amountStr}
                                        onChange={(e) => {
                                            const next = e.target.value;
                                            setAmountStr(next);
                                            form.setData(
                                                'amount_centavos',
                                                pesosToCentavosOrNull(next),
                                            );
                                        }}
                                        onBlur={() => {
                                            setAmountStr(
                                                centavosToPesos(
                                                    form.data.amount_centavos,
                                                ),
                                            );
                                        }}
                                        placeholder="e.g. 500.00"
                                        className="pl-7 text-right tabular-nums"
                                    />
                                </div>
                                <InputError
                                    message={form.errors.amount_centavos}
                                />
                            </div>
                        )}

                        {selectedType?.calc_method === 'percent' && (
                            <div className="grid gap-2">
                                <Label htmlFor="percent_basis_points">
                                    Percent of gross
                                </Label>
                                <div className="relative">
                                    <Input
                                        id="percent_basis_points"
                                        inputMode="decimal"
                                        pattern="[0-9]*\.?[0-9]*"
                                        value={percentStr}
                                        onChange={(e) => {
                                            const next = e.target.value;
                                            setPercentStr(next);
                                            form.setData(
                                                'percent_basis_points',
                                                basisPointsFromPercent(next),
                                            );
                                        }}
                                        onBlur={() => {
                                            setPercentStr(
                                                percentFromBasisPoints(
                                                    form.data
                                                        .percent_basis_points,
                                                ),
                                            );
                                        }}
                                        placeholder="e.g. 2.50"
                                        className="pr-8 text-right tabular-nums"
                                    />
                                    <span
                                        aria-hidden="true"
                                        className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground"
                                    >
                                        %
                                    </span>
                                </div>
                                <InputError
                                    message={form.errors.percent_basis_points}
                                />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="schedule">Schedule</Label>
                            <Select
                                value={form.data.schedule}
                                onValueChange={(v) =>
                                    form.setData(
                                        'schedule',
                                        v as SubscriptionSchedule,
                                    )
                                }
                            >
                                <SelectTrigger id="schedule" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {SCHEDULE_OPTIONS.map((opt) => (
                                        <SelectItem key={opt} value={opt}>
                                            {SCHEDULE_LABEL[opt]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.schedule} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="effective_from">
                                Effective from
                            </Label>
                            <DatePicker
                                id="effective_from"
                                value={form.data.effective_from}
                                onChange={(v) =>
                                    form.setData('effective_from', v)
                                }
                                placeholder="Select start date"
                                ariaInvalid={Boolean(
                                    form.errors.effective_from,
                                )}
                            />
                            <InputError message={form.errors.effective_from} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="effective_to">
                                Effective to (optional)
                            </Label>
                            <DatePicker
                                id="effective_to"
                                value={form.data.effective_to}
                                onChange={(v) =>
                                    form.setData('effective_to', v)
                                }
                                placeholder="Open-ended"
                                ariaInvalid={Boolean(form.errors.effective_to)}
                            />
                            <InputError message={form.errors.effective_to} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">Notes</Label>
                            <textarea
                                id="notes"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                placeholder="Optional context"
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputError message={form.errors.notes} />
                        </div>
                    </div>

                    <SheetFooter className="flex-row justify-end gap-2 border-t bg-background">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={handleCancel}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing || (isEdit && !form.isDirty)
                            }
                        >
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save changes'
                                  : 'Add deduction'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
