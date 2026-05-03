import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import {
    store as allowanceStore,
    update as allowanceUpdate,
} from '@/actions/App/Http/Controllers/Employees/EmployeeAllowanceController';
import { SCHEDULE_LABEL } from '@/components/employees/schedule-label';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
    AllowanceRef,
    EmployeeAllowanceRow,
    SubscriptionSchedule,
} from '@/types';

/**
 * Create / edit a per-employee allowance subscription (Week 7, Chunk 6).
 * Allowances are always fixed-amount — no calc_method switch is needed.
 */

interface AllowanceEditSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lmsStaffId: number;
    allowance?: EmployeeAllowanceRow;
    allowanceOptions: AllowanceRef[];
}

interface FormShape {
    allowance_id: number | '';
    amount_centavos: number | null;
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

function buildDefaults(allowance?: EmployeeAllowanceRow): FormShape {
    if (allowance === undefined) {
        return {
            allowance_id: '',
            amount_centavos: null,
            schedule: 'every_run',
            effective_from: '',
            effective_to: '',
            notes: '',
        };
    }

    return {
        allowance_id: allowance.allowance.id,
        amount_centavos: allowance.amount_centavos,
        schedule: allowance.schedule,
        effective_from: allowance.effective_from,
        effective_to: allowance.effective_to ?? '',
        notes: allowance.notes ?? '',
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

export function AllowanceEditSheet({
    open,
    onOpenChange,
    lmsStaffId,
    allowance,
    allowanceOptions,
}: AllowanceEditSheetProps) {
    const isEdit = allowance !== undefined;
    const form = useForm<FormShape>(buildDefaults(allowance));

    const [amountStr, setAmountStr] = useState(() =>
        centavosToPesos(form.data.amount_centavos),
    );

    const rowKey = allowance ? `edit:${allowance.id}` : 'create';

    useEffect(() => {
        const next = buildDefaults(allowance);
        form.setDefaults(next);
        form.setData(next);
        form.clearErrors();
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setAmountStr(centavosToPesos(next.amount_centavos));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rowKey]);

    const selectedAllowance = allowanceOptions.find(
        (a) => a.id === form.data.allowance_id,
    );

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        const onSuccess = () => {
            toast.success(isEdit ? 'Allowance updated.' : 'Allowance added.');
            onOpenChange(false);
        };

        if (isEdit && allowance) {
            form.patch(
                allowanceUpdate({
                    staffId: lmsStaffId,
                    employeeAllowance: allowance.id,
                }).url,
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess,
                },
            );

            return;
        }

        form.post(allowanceStore({ staffId: lmsStaffId }).url, {
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
                        {isEdit ? 'Edit allowance' : 'Add allowance'}
                    </SheetTitle>
                    <SheetDescription>
                        Recurring allowance applied on the chosen schedule.
                    </SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={handleSubmit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <div className="flex-1 space-y-4 overflow-y-auto p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="allowance_id">Allowance</Label>
                            <Select
                                value={
                                    form.data.allowance_id === ''
                                        ? ''
                                        : String(form.data.allowance_id)
                                }
                                onValueChange={(v) =>
                                    form.setData('allowance_id', Number(v))
                                }
                            >
                                <SelectTrigger
                                    id="allowance_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Select an allowance" />
                                </SelectTrigger>
                                <SelectContent>
                                    {allowanceOptions.map((opt) => (
                                        <SelectItem
                                            key={opt.id}
                                            value={String(opt.id)}
                                        >
                                            <span>{opt.name}</span>
                                            <span className="ml-2 font-mono text-xs text-muted-foreground">
                                                {opt.code}
                                            </span>
                                            {opt.is_de_minimis && (
                                                <Badge
                                                    variant="secondary"
                                                    className="ml-2"
                                                >
                                                    De-minimis
                                                </Badge>
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.allowance_id} />
                            {selectedAllowance && (
                                <p className="text-xs text-muted-foreground">
                                    {selectedAllowance.is_taxable
                                        ? 'Taxable — folds into BIR base.'
                                        : 'Non-taxable — added to net pay only.'}
                                </p>
                            )}
                        </div>

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
                                    placeholder="e.g. 2000.00"
                                    className="pl-7 text-right tabular-nums"
                                />
                            </div>
                            <InputError
                                message={form.errors.amount_centavos}
                            />
                        </div>

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
                                <SelectTrigger
                                    id="schedule"
                                    className="w-full"
                                >
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
                            <InputError
                                message={form.errors.effective_from}
                            />
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
                                  : 'Add allowance'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
