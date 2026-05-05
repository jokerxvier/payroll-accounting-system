import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { update as profileUpdate } from '@/routes/employees/profile';
import type {
    AvailableContribution,
    EmployeeDetail,
    EmploymentClassification,
    EmploymentTypeOption,
    PayFrequency,
} from '@/types';

interface EmployeeEditSheetProps {
    employee: EmployeeDetail;
    employmentTypeOptions: EmploymentTypeOption[];
    /**
     * Statutory contributions HR can flag the employee exempt from. Sourced
     * from the controller's `availableContributions` prop. When the list is
     * empty the exemptions section is hidden defensively.
     */
    availableContributions: AvailableContribution[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

type FormShape = {
    basic_salary_centavos: number;
    employment_classification: EmploymentClassification;
    pay_frequency: PayFrequency;
    tax_status: string;
    tin: string;
    sss_number: string;
    philhealth_number: string;
    pagibig_number: string;
    bank_name: string;
    bank_account_number: string;
    bank_account_name: string;
    date_hired: string;
    date_terminated: string;
    is_active: boolean;
    exempted_contribution_codes: string[];
};

const PAY_FREQUENCY_OPTIONS: { value: PayFrequency; label: string }[] = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'semi_monthly', label: 'Semi-monthly' },
];

function buildDefaults(employee: EmployeeDetail): FormShape {
    const profile = employee.profile;

    if (profile === null) {
        // Caller is expected to gate the sheet behind a non-null profile.
        // Defensive defaults keep TypeScript happy without runtime branches.
        return {
            basic_salary_centavos: 0,
            employment_classification: 'regular',
            pay_frequency: 'semi_monthly',
            tax_status: '',
            tin: '',
            sss_number: '',
            philhealth_number: '',
            pagibig_number: '',
            bank_name: '',
            bank_account_number: '',
            bank_account_name: '',
            date_hired: '',
            date_terminated: '',
            is_active: true,
            exempted_contribution_codes: [],
        };
    }

    return {
        basic_salary_centavos: profile.basic_salary_centavos,
        employment_classification: profile.employment_classification,
        pay_frequency: profile.pay_frequency,
        tax_status: profile.tax_status ?? '',
        tin: profile.tin ?? '',
        sss_number: profile.sss_number ?? '',
        philhealth_number: profile.philhealth_number ?? '',
        pagibig_number: profile.pagibig_number ?? '',
        bank_name: profile.bank_name ?? '',
        bank_account_number: profile.bank_account_number ?? '',
        bank_account_name: profile.bank_account_name ?? '',
        date_hired: profile.date_hired ?? '',
        date_terminated: profile.date_terminated ?? '',
        is_active: profile.is_active,
        // Defensive `?? []` — older payloads predating the column may still
        // arrive with the field undefined (e.g. cached Inertia visits).
        exempted_contribution_codes: profile.exempted_contribution_codes ?? [],
    };
}

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

export function EmployeeEditSheet({
    employee,
    employmentTypeOptions,
    availableContributions,
    open,
    onOpenChange,
}: EmployeeEditSheetProps) {
    const form = useForm<FormShape>(buildDefaults(employee));
    const [salaryStr, setSalaryStr] = useState(() =>
        centavosToPesos(form.data.basic_salary_centavos),
    );

    // Re-seed defaults when the underlying profile changes — either because
    // the parent navigated to a new employee or because a successful PATCH
    // returned with fresh values. Keying on profile.id + updated_at covers
    // both cases without resetting while the user is mid-edit.
    const profileKey = employee.profile
        ? `${employee.profile.id}:${employee.profile.updated_at ?? ''}`
        : 'none';

    useEffect(() => {
        const next = buildDefaults(employee);
        form.setDefaults(next);
        // Push new values directly into form.data — relying on reset() after
        // setDefaults misses a re-render in Inertia v3's useForm and leaves
        // controlled inputs (Switch, Select) reading stale defaults.
        form.setData(next);
        form.clearErrors();
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setSalaryStr(centavosToPesos(next.basic_salary_centavos));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [profileKey]);

    /**
     * Add or remove a statutory contribution code from the exemption list.
     * Pure list-mutation routed through `setData` so Inertia's form picks up
     * the change (and its dirty / errors machinery still works as expected).
     */
    const toggleExemption = (code: string, checked: boolean): void => {
        const current = form.data.exempted_contribution_codes;
        const next = checked
            ? current.includes(code)
                ? current
                : [...current, code]
            : current.filter((existing) => existing !== code);

        form.setData('exempted_contribution_codes', next);
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.patch(profileUpdate(employee.lms_staff_id).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Profile updated');
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
                className="flex w-full flex-col gap-0 p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b">
                    <SheetTitle className="font-serif text-lg">
                        Edit profile
                    </SheetTitle>
                    <SheetDescription>
                        {employee.full_name ?? '—'}
                    </SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={handleSubmit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <div className="flex-1 space-y-6 overflow-y-auto p-4">
                        <section className="space-y-4">
                            <Heading
                                variant="small"
                                title="Salary &amp; classification"
                                description="Basic compensation and employment terms."
                            />

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
                                            // Snap to a normalized 2dp display
                                            // when the user leaves the field.
                                            setSalaryStr(
                                                centavosToPesos(
                                                    form.data
                                                        .basic_salary_centavos,
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
                                    message={
                                        form.errors.employment_classification
                                    }
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="pay_frequency">
                                    Pay frequency
                                </Label>
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
                                <InputError
                                    message={form.errors.pay_frequency}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="tax_status">Tax status</Label>
                                <Input
                                    id="tax_status"
                                    type="text"
                                    maxLength={8}
                                    value={form.data.tax_status}
                                    onChange={(e) =>
                                        form.setData(
                                            'tax_status',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. S, ME1"
                                />
                                <InputError message={form.errors.tax_status} />
                            </div>
                        </section>

                        <Separator />

                        <section className="space-y-4">
                            <Heading
                                variant="small"
                                title="Government IDs"
                                description="Stored encrypted at rest."
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="tin">TIN</Label>
                                <Input
                                    id="tin"
                                    type="text"
                                    maxLength={32}
                                    value={form.data.tin}
                                    onChange={(e) =>
                                        form.setData('tin', e.target.value)
                                    }
                                    className="font-mono"
                                />
                                <InputError message={form.errors.tin} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="sss_number">SSS number</Label>
                                <Input
                                    id="sss_number"
                                    type="text"
                                    maxLength={32}
                                    value={form.data.sss_number}
                                    onChange={(e) =>
                                        form.setData(
                                            'sss_number',
                                            e.target.value,
                                        )
                                    }
                                    className="font-mono"
                                />
                                <InputError message={form.errors.sss_number} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="philhealth_number">
                                    PhilHealth number
                                </Label>
                                <Input
                                    id="philhealth_number"
                                    type="text"
                                    maxLength={32}
                                    value={form.data.philhealth_number}
                                    onChange={(e) =>
                                        form.setData(
                                            'philhealth_number',
                                            e.target.value,
                                        )
                                    }
                                    className="font-mono"
                                />
                                <InputError
                                    message={form.errors.philhealth_number}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="pagibig_number">
                                    Pag-IBIG number
                                </Label>
                                <Input
                                    id="pagibig_number"
                                    type="text"
                                    maxLength={32}
                                    value={form.data.pagibig_number}
                                    onChange={(e) =>
                                        form.setData(
                                            'pagibig_number',
                                            e.target.value,
                                        )
                                    }
                                    className="font-mono"
                                />
                                <InputError
                                    message={form.errors.pagibig_number}
                                />
                            </div>
                        </section>

                        {availableContributions.length > 0 && (
                            <>
                                <Separator />

                                <section
                                    className="space-y-4"
                                    aria-labelledby="exemptions-heading"
                                >
                                    <div className="space-y-1">
                                        <h3
                                            id="exemptions-heading"
                                            className="text-sm font-medium"
                                        >
                                            Statutory exemptions
                                        </h3>
                                        <p
                                            id="exemptions-description"
                                            className="text-xs text-muted-foreground"
                                        >
                                            Selected contributions will not be
                                            deducted from this employee's
                                            payroll. Use sparingly — most
                                            employees should be subscribed to
                                            all active statutory contributions.
                                        </p>
                                    </div>

                                    <ul
                                        className="space-y-2"
                                        aria-describedby="exemptions-description"
                                    >
                                        {availableContributions.map(
                                            (contribution) => {
                                                const checkboxId = `exemption-${contribution.code}`;
                                                const isChecked =
                                                    form.data.exempted_contribution_codes.includes(
                                                        contribution.code,
                                                    );

                                                return (
                                                    <li key={contribution.code}>
                                                        <Label
                                                            htmlFor={checkboxId}
                                                            className="flex items-start gap-3 rounded-md border p-3 hover:bg-muted/30"
                                                        >
                                                            <Checkbox
                                                                id={checkboxId}
                                                                checked={
                                                                    isChecked
                                                                }
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    toggleExemption(
                                                                        contribution.code,
                                                                        checked ===
                                                                            true,
                                                                    )
                                                                }
                                                                className="mt-0.5"
                                                            />
                                                            <div className="flex-1 space-y-0.5">
                                                                <span className="font-mono text-sm">
                                                                    {
                                                                        contribution.code
                                                                    }
                                                                </span>
                                                                <p className="text-xs text-muted-foreground">
                                                                    {
                                                                        contribution.label
                                                                    }
                                                                </p>
                                                            </div>
                                                        </Label>
                                                    </li>
                                                );
                                            },
                                        )}
                                    </ul>
                                    <InputError
                                        message={
                                            form.errors
                                                .exempted_contribution_codes
                                        }
                                    />
                                </section>
                            </>
                        )}

                        <Separator />

                        <section className="space-y-4">
                            <Heading
                                variant="small"
                                title="Bank account"
                                description="Used for payslip remittance."
                            />

                            <div className="grid gap-2">
                                <Label htmlFor="bank_name">Bank name</Label>
                                <Input
                                    id="bank_name"
                                    type="text"
                                    maxLength={100}
                                    value={form.data.bank_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'bank_name',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.bank_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="bank_account_number">
                                    Account number
                                </Label>
                                <Input
                                    id="bank_account_number"
                                    type="text"
                                    maxLength={64}
                                    value={form.data.bank_account_number}
                                    onChange={(e) =>
                                        form.setData(
                                            'bank_account_number',
                                            e.target.value,
                                        )
                                    }
                                    className="font-mono"
                                />
                                <InputError
                                    message={form.errors.bank_account_number}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="bank_account_name">
                                    Account name
                                </Label>
                                <Input
                                    id="bank_account_name"
                                    type="text"
                                    maxLength={150}
                                    value={form.data.bank_account_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'bank_account_name',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.bank_account_name}
                                />
                            </div>
                        </section>

                        <Separator />

                        <section className="space-y-4">
                            <Heading
                                variant="small"
                                title="Status"
                                description="Active flag and employment dates."
                            />

                            <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                                <div className="space-y-0.5">
                                    <Label htmlFor="is_active">Active</Label>
                                    <p className="text-xs text-muted-foreground">
                                        Inactive profiles are excluded from
                                        payroll runs.
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

                            <div className="grid gap-2">
                                <Label htmlFor="date_hired">Date hired</Label>
                                <DatePicker
                                    id="date_hired"
                                    value={form.data.date_hired}
                                    onChange={(v) =>
                                        form.setData('date_hired', v)
                                    }
                                    placeholder="Select date hired"
                                    ariaInvalid={Boolean(
                                        form.errors.date_hired,
                                    )}
                                />
                                <InputError message={form.errors.date_hired} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="date_terminated">
                                    Date terminated
                                </Label>
                                <DatePicker
                                    id="date_terminated"
                                    value={form.data.date_terminated}
                                    onChange={(v) =>
                                        form.setData('date_terminated', v)
                                    }
                                    placeholder="Select termination date"
                                    ariaInvalid={Boolean(
                                        form.errors.date_terminated,
                                    )}
                                />
                                <InputError
                                    message={form.errors.date_terminated}
                                />
                            </div>
                        </section>
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
                            disabled={form.processing || !form.isDirty}
                        >
                            {form.processing ? 'Saving…' : 'Save changes'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
