import { useForm } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import {
    store as loanStore,
    update as loanUpdate,
} from '@/actions/App/Http/Controllers/Employees/EmployeeLoanController';
import { SCHEDULE_LABEL } from '@/components/employees/schedule-label';
import InputError from '@/components/input-error';
import { Money } from '@/components/money';
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
import type { EmployeeLoanRow, SubscriptionSchedule } from '@/types';

/**
 * Create / edit a per-employee loan (Week 7, Chunk 6).
 *
 * Editable fields differ between create and edit modes per the FormRequest
 * contract:
 *   - Create: code, principal, monthly amortization, schedule, started_on, notes
 *   - Edit:   code, monthly amortization, schedule, notes
 *
 * In edit mode, principal / outstanding / started_on are surfaced as
 * read-only context so the user understands why those fields are not
 * editable (changing them would corrupt the (principal − outstanding)
 * progress computation or race the Week-9 batch persistence path).
 */

interface LoanEditSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lmsStaffId: number;
    loan?: EmployeeLoanRow;
}

interface FormShape {
    code: string;
    principal_centavos: number | null;
    monthly_amortization_centavos: number | null;
    schedule: SubscriptionSchedule;
    started_on: string;
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

function buildDefaults(loan?: EmployeeLoanRow): FormShape {
    if (loan === undefined) {
        return {
            code: '',
            principal_centavos: null,
            monthly_amortization_centavos: null,
            schedule: 'monthly_first',
            started_on: '',
            notes: '',
        };
    }

    return {
        code: loan.code,
        principal_centavos: loan.principal_centavos,
        monthly_amortization_centavos: loan.monthly_amortization_centavos,
        schedule: loan.schedule,
        started_on: loan.started_on,
        notes: loan.notes ?? '',
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

function formatDateSafe(value: string): string {
    try {
        return format(parseISO(value), 'MMM d, yyyy');
    } catch {
        return value;
    }
}

export function LoanEditSheet({
    open,
    onOpenChange,
    lmsStaffId,
    loan,
}: LoanEditSheetProps) {
    const isEdit = loan !== undefined;
    const form = useForm<FormShape>(buildDefaults(loan));

    const [principalStr, setPrincipalStr] = useState(() =>
        centavosToPesos(form.data.principal_centavos),
    );
    const [amortStr, setAmortStr] = useState(() =>
        centavosToPesos(form.data.monthly_amortization_centavos),
    );

    const rowKey = loan ? `edit:${loan.id}` : 'create';

    useEffect(() => {
        const next = buildDefaults(loan);
        form.setDefaults(next);
        form.setData(next);
        form.clearErrors();
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setPrincipalStr(centavosToPesos(next.principal_centavos));
         
        setAmortStr(centavosToPesos(next.monthly_amortization_centavos));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rowKey]);

    const handleSubmit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        const onSuccess = () => {
            toast.success(isEdit ? 'Loan updated.' : 'Loan added.');
            onOpenChange(false);
        };

        if (isEdit && loan) {
            // The FormRequest only accepts code, monthly_amortization_centavos,
            // schedule, notes — extra keys are ignored server-side, but we
            // omit them client-side too so the wire payload matches the
            // backend contract exactly.
            form.transform((data) => ({
                code: data.code,
                monthly_amortization_centavos:
                    data.monthly_amortization_centavos,
                schedule: data.schedule,
                notes: data.notes,
            }));

            form.patch(
                loanUpdate({
                    staffId: lmsStaffId,
                    employeeLoan: loan.id,
                }).url,
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess,
                },
            );

            return;
        }

        form.post(loanStore({ staffId: lmsStaffId }).url, {
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
                        {isEdit ? 'Edit loan' : 'Add loan'}
                    </SheetTitle>
                    <SheetDescription>
                        {isEdit
                            ? 'Principal and start date are immutable. Refinance by closing and creating a new loan.'
                            : 'Initial loan setup. Outstanding balance is tracked automatically.'}
                    </SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={handleSubmit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <div className="flex-1 space-y-4 overflow-y-auto p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="code">Loan code</Label>
                            <Input
                                id="code"
                                type="text"
                                maxLength={64}
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                placeholder="e.g. SSS-2026-001"
                                className="font-mono"
                                required
                            />
                            <InputError message={form.errors.code} />
                        </div>

                        {isEdit && loan ? (
                            <ReadOnlyLoanContext loan={loan} />
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="principal_centavos">
                                    Principal
                                </Label>
                                <div className="relative">
                                    <span
                                        aria-hidden="true"
                                        className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                                    >
                                        ₱
                                    </span>
                                    <Input
                                        id="principal_centavos"
                                        inputMode="decimal"
                                        pattern="[0-9]*\.?[0-9]*"
                                        value={principalStr}
                                        onChange={(e) => {
                                            const next = e.target.value;
                                            setPrincipalStr(next);
                                            form.setData(
                                                'principal_centavos',
                                                pesosToCentavosOrNull(next),
                                            );
                                        }}
                                        onBlur={() => {
                                            setPrincipalStr(
                                                centavosToPesos(
                                                    form.data
                                                        .principal_centavos,
                                                ),
                                            );
                                        }}
                                        placeholder="e.g. 30000.00"
                                        className="pl-7 text-right tabular-nums"
                                        required
                                    />
                                </div>
                                <InputError
                                    message={form.errors.principal_centavos}
                                />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="monthly_amortization_centavos">
                                Monthly amortization
                            </Label>
                            <div className="relative">
                                <span
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                                >
                                    ₱
                                </span>
                                <Input
                                    id="monthly_amortization_centavos"
                                    inputMode="decimal"
                                    pattern="[0-9]*\.?[0-9]*"
                                    value={amortStr}
                                    onChange={(e) => {
                                        const next = e.target.value;
                                        setAmortStr(next);
                                        form.setData(
                                            'monthly_amortization_centavos',
                                            pesosToCentavosOrNull(next),
                                        );
                                    }}
                                    onBlur={() => {
                                        setAmortStr(
                                            centavosToPesos(
                                                form.data
                                                    .monthly_amortization_centavos,
                                            ),
                                        );
                                    }}
                                    placeholder="e.g. 2500.00"
                                    className="pl-7 text-right tabular-nums"
                                    required
                                />
                            </div>
                            <InputError
                                message={
                                    form.errors.monthly_amortization_centavos
                                }
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

                        {!isEdit && (
                            <div className="grid gap-2">
                                <Label htmlFor="started_on">Started on</Label>
                                <DatePicker
                                    id="started_on"
                                    value={form.data.started_on}
                                    onChange={(v) =>
                                        form.setData('started_on', v)
                                    }
                                    placeholder="Select start date"
                                    ariaInvalid={Boolean(
                                        form.errors.started_on,
                                    )}
                                />
                                <InputError
                                    message={form.errors.started_on}
                                />
                            </div>
                        )}

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
                                  : 'Add loan'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}

function ReadOnlyLoanContext({ loan }: { loan: EmployeeLoanRow }) {
    return (
        <div className="space-y-2 rounded-md border bg-muted/30 p-3">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                Read-only
            </p>
            <dl className="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Principal
                    </dt>
                    <dd className="tabular-nums">
                        <Money amount={loan.principal_centavos / 100} />
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Outstanding
                    </dt>
                    <dd className="tabular-nums">
                        <Money
                            amount={loan.outstanding_balance_centavos / 100}
                        />
                    </dd>
                </div>
                <div className="col-span-2">
                    <dt className="text-xs text-muted-foreground">
                        Started on
                    </dt>
                    <dd>{formatDateSafe(loan.started_on)}</dd>
                </div>
            </dl>
        </div>
    );
}
