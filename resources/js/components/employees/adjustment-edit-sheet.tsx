import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import {
    store as adjustmentStore,
    update as adjustmentUpdate,
} from '@/actions/App/Http/Controllers/Employees/PayrollAdjustmentController';
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
import { Switch } from '@/components/ui/switch';
import type { PayrollAdjustmentKind, PayrollAdjustmentRow } from '@/types';

/**
 * Create / edit a one-off payroll adjustment (Week 7, Chunk 6).
 *
 * `kind` (addition vs deduction) and `is_taxable` are independent — a taxable
 * deduction (e.g. wage garnishment that reduces taxable income) is just as
 * valid as a non-taxable addition (e.g. de-minimis bonus).
 */

interface AdjustmentEditSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lmsStaffId: number;
    adjustment?: PayrollAdjustmentRow;
}

interface FormShape {
    kind: PayrollAdjustmentKind;
    is_taxable: boolean;
    amount_centavos: number | null;
    applies_on: string;
    label: string;
    reason: string;
    [key: string]: string | number | boolean | null;
}

function buildDefaults(adjustment?: PayrollAdjustmentRow): FormShape {
    if (adjustment === undefined) {
        return {
            kind: 'addition',
            is_taxable: true,
            amount_centavos: null,
            applies_on: '',
            label: '',
            reason: '',
        };
    }

    return {
        kind: adjustment.kind,
        is_taxable: adjustment.is_taxable,
        amount_centavos: adjustment.amount_centavos,
        applies_on: adjustment.applies_on,
        label: adjustment.label,
        reason: adjustment.reason ?? '',
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

export function AdjustmentEditSheet({
    open,
    onOpenChange,
    lmsStaffId,
    adjustment,
}: AdjustmentEditSheetProps) {
    const isEdit = adjustment !== undefined;
    const form = useForm<FormShape>(buildDefaults(adjustment));

    const [amountStr, setAmountStr] = useState(() =>
        centavosToPesos(form.data.amount_centavos),
    );

    const rowKey = adjustment ? `edit:${adjustment.id}` : 'create';

    useEffect(() => {
        const next = buildDefaults(adjustment);
        form.setDefaults(next);
        form.setData(next);
        form.clearErrors();
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setAmountStr(centavosToPesos(next.amount_centavos));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rowKey]);

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        const onSuccess = () => {
            toast.success(isEdit ? 'Adjustment updated.' : 'Adjustment added.');
            onOpenChange(false);
        };

        if (isEdit && adjustment) {
            form.patch(
                adjustmentUpdate({
                    staffId: lmsStaffId,
                    payrollAdjustment: adjustment.id,
                }).url,
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess,
                },
            );

            return;
        }

        form.post(adjustmentStore({ staffId: lmsStaffId }).url, {
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
                        {isEdit ? 'Edit adjustment' : 'Add adjustment'}
                    </SheetTitle>
                    <SheetDescription>
                        One-off bonus, single deduction, or back-pay correction.
                    </SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={handleSubmit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <div className="flex-1 space-y-4 overflow-y-auto p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="kind">Kind</Label>
                            <Select
                                value={form.data.kind}
                                onValueChange={(v) =>
                                    form.setData(
                                        'kind',
                                        v as PayrollAdjustmentKind,
                                    )
                                }
                            >
                                <SelectTrigger id="kind" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="addition">
                                        Addition
                                    </SelectItem>
                                    <SelectItem value="deduction">
                                        Deduction
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.kind} />
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                            <div className="space-y-0.5">
                                <Label htmlFor="is_taxable">Taxable</Label>
                                <p className="text-xs text-muted-foreground">
                                    When ON, this adjustment folds into the BIR
                                    taxable base.
                                </p>
                            </div>
                            <Switch
                                id="is_taxable"
                                checked={form.data.is_taxable}
                                onCheckedChange={(checked) =>
                                    form.setData('is_taxable', checked)
                                }
                            />
                        </div>
                        <InputError message={form.errors.is_taxable} />

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
                                    placeholder="e.g. 5000.00"
                                    className="pl-7 text-right tabular-nums"
                                    required
                                />
                            </div>
                            <InputError message={form.errors.amount_centavos} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="applies_on">Applies on</Label>
                            <DatePicker
                                id="applies_on"
                                value={form.data.applies_on}
                                onChange={(v) => form.setData('applies_on', v)}
                                placeholder="Select calendar date"
                                ariaInvalid={Boolean(form.errors.applies_on)}
                            />
                            <p className="text-xs text-muted-foreground">
                                The pay period this adjustment runs against is
                                resolved by date at compute time.
                            </p>
                            <InputError message={form.errors.applies_on} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="label">Label</Label>
                            <Input
                                id="label"
                                type="text"
                                maxLength={120}
                                value={form.data.label}
                                onChange={(e) =>
                                    form.setData('label', e.target.value)
                                }
                                placeholder="e.g. Performance bonus"
                                required
                            />
                            <InputError message={form.errors.label} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="reason">Reason (optional)</Label>
                            <textarea
                                id="reason"
                                rows={3}
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData('reason', e.target.value)
                                }
                                placeholder="Optional explanation for the audit log"
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputError message={form.errors.reason} />
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
                                  : 'Add adjustment'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
