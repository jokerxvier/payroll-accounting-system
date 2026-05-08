import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { store as profileStore } from '@/routes/employees/profile';
import type {
    EmploymentClassification,
    EmploymentTypeOption,
    PayFrequency,
} from '@/types';

/**
 * "Set up profile" affordance for /employees rows whose staff record has no
 * payroll profile yet. POSTs to the existing `employees.profile.store` route
 * with the four create-time required fields (basic_salary_centavos,
 * employment_classification, pay_frequency, is_active).
 *
 * Authorization mirrors the page-level gate: only roles that can `create` an
 * EmployeeProfile (super-admin, payroll-officer, hr) see the affordance — the
 * server enforces the same via {@see EmployeeProfileStoreRequest::authorize}.
 */

interface ProfileSetupSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    staffId: number;
    staffName: string;
    employmentTypeOptions: EmploymentTypeOption[];
}

interface FormShape {
    basic_salary_centavos: number;
    employment_classification: EmploymentClassification;
    pay_frequency: PayFrequency;
    is_active: boolean;
    [key: string]: string | number | boolean;
}

const PAY_FREQUENCY_OPTIONS: { value: PayFrequency; label: string }[] = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'semi_monthly', label: 'Semi-monthly' },
];

const DEFAULTS: FormShape = {
    basic_salary_centavos: 0,
    employment_classification: 'regular',
    pay_frequency: 'monthly',
    is_active: true,
};

function centavosToPesos(centavos: number): string {
    return (centavos / 100).toFixed(2);
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

export function ProfileSetupSheet({
    open,
    onOpenChange,
    staffId,
    staffName,
    employmentTypeOptions,
}: ProfileSetupSheetProps) {
    const form = useForm<FormShape>({ ...DEFAULTS });
    const [salaryStr, setSalaryStr] = useState(() =>
        centavosToPesos(DEFAULTS.basic_salary_centavos),
    );

    // Reset the form whenever the sheet opens so a previous "Set up" attempt
    // (cancelled or for a different staff) does not leak its values into a
    // fresh setup. Keying on `${open}:${staffId}` covers both cases.
    const sheetKey = `${open}:${staffId}`;

    useEffect(() => {
        if (!open) {
            return;
        }

        form.setDefaults({ ...DEFAULTS });
        form.setData({ ...DEFAULTS });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setSalaryStr(centavosToPesos(DEFAULTS.basic_salary_centavos));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sheetKey]);

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.post(profileStore(staffId).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success(`Set up profile for ${staffName}.`);
                onOpenChange(false);
            },
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
                className="flex w-full flex-col gap-0 p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b">
                    <SheetTitle className="font-serif text-lg">
                        Set up payroll profile
                    </SheetTitle>
                    <SheetDescription>{staffName}</SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={handleSubmit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <div className="flex-1 space-y-4 overflow-y-auto p-4">
                        <p className="text-xs text-muted-foreground">
                            Creates a payroll profile for this staff member.
                            Fill in the basics now — government IDs, bank
                            details, and statutory exemptions can be edited from
                            the profile page afterwards.
                        </p>

                        <div className="grid gap-2">
                            <Label htmlFor="basic_salary_centavos">
                                Basic salary
                            </Label>
                            <div className="relative">
                                <span
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                                >
                                    ₱
                                </span>
                                <Input
                                    id="basic_salary_centavos"
                                    inputMode="decimal"
                                    pattern="[0-9]*\.?[0-9]*"
                                    value={salaryStr}
                                    onChange={(e) => {
                                        const next = e.target.value;
                                        setSalaryStr(next);
                                        form.setData(
                                            'basic_salary_centavos',
                                            pesosToCentavos(next),
                                        );
                                    }}
                                    onBlur={() => {
                                        setSalaryStr(
                                            centavosToPesos(
                                                form.data.basic_salary_centavos,
                                            ),
                                        );
                                    }}
                                    className="pl-7 text-right tabular-nums"
                                />
                            </div>
                            <InputError
                                message={form.errors.basic_salary_centavos}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="employment_classification">
                                Employment type
                            </Label>
                            <Select
                                value={form.data.employment_classification}
                                onValueChange={(v) =>
                                    form.setData(
                                        'employment_classification',
                                        v as EmploymentClassification,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="employment_classification"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Select employment type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {employmentTypeOptions.map((opt) => (
                                        <SelectItem
                                            key={opt.value}
                                            value={opt.value}
                                        >
                                            {opt.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={form.errors.employment_classification}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="pay_frequency">Pay frequency</Label>
                            <Select
                                value={form.data.pay_frequency}
                                onValueChange={(v) =>
                                    form.setData(
                                        'pay_frequency',
                                        v as PayFrequency,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="pay_frequency"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Select pay frequency" />
                                </SelectTrigger>
                                <SelectContent>
                                    {PAY_FREQUENCY_OPTIONS.map((opt) => (
                                        <SelectItem
                                            key={opt.value}
                                            value={opt.value}
                                        >
                                            {opt.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.pay_frequency} />
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                            <div className="space-y-0.5">
                                <Label htmlFor="is_active">Active</Label>
                                <p className="text-xs text-muted-foreground">
                                    Inactive profiles are excluded from payroll
                                    runs.
                                </p>
                            </div>
                            <Switch
                                id="is_active"
                                checked={form.data.is_active}
                                onCheckedChange={(checked) =>
                                    form.setData('is_active', checked)
                                }
                            />
                        </div>
                        <InputError message={form.errors.is_active} />
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
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Setting up…' : 'Set up profile'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
