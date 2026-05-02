import { useForm } from '@inertiajs/react';
import { Banknote, IdCard, Loader2, ToggleRight, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ComponentType, FormEvent } from 'react';
import { toast } from 'sonner';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import {
    json as profileJson,
    update as profileUpdate,
} from '@/routes/employees/profile';
import type {
    EmployeeProfile,
    EmploymentClassification,
    EmploymentTypeOption,
    PayFrequency,
} from '@/types';

type Section = 'salary' | 'status' | 'gov_ids' | 'bank';

interface EmployeeRowEditorProps {
    staffId: number;
    fullName: string | null;
    employmentTypeOptions: EmploymentTypeOption[];
    onClose: () => void;
}

const SECTIONS: {
    id: Section;
    label: string;
    icon: ComponentType<{ className?: string }>;
}[] = [
    { id: 'salary', label: 'Salary & classification', icon: Banknote },
    { id: 'status', label: 'Status', icon: ToggleRight },
    { id: 'gov_ids', label: 'Government IDs', icon: IdCard },
    { id: 'bank', label: 'Bank account', icon: Wallet },
];

const PAY_FREQUENCY_OPTIONS: { value: PayFrequency; label: string }[] = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'semi_monthly', label: 'Semi-monthly' },
];

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

export function EmployeeRowEditor({
    staffId,
    fullName,
    employmentTypeOptions,
    onClose,
}: EmployeeRowEditorProps) {
    const [activeTab, setActiveTab] = useState<Section>('salary');
    const [profile, setProfile] = useState<EmployeeProfile | null | undefined>(
        undefined,
    );
    const [fetchError, setFetchError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (profile !== undefined) {
            return;
        }

        let cancelled = false;
        // eslint-disable-next-line react-hooks/set-state-in-effect
        setLoading(true);
        setFetchError(null);

        fetch(profileJson(staffId).url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(async (res) => {
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }

                return (await res.json()) as {
                    profile: EmployeeProfile | null;
                };
            })
            .then((data) => {
                if (!cancelled) {
                    setProfile(data.profile);
                }
            })
            .catch((err: unknown) => {
                if (!cancelled) {
                    setFetchError(
                        err instanceof Error
                            ? err.message
                            : 'Could not load profile',
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [profile, staffId]);

    const handleSaved = () => {
        // Invalidate the cached profile so reopening fetches fresh data.
        setProfile(undefined);
        onClose();
    };

    const retryFetch = () => {
        setProfile(undefined);
        setFetchError(null);
    };

    return (
        <div className="rounded-md border bg-background">
            <div className="flex items-center justify-between gap-4 border-b px-4 py-3">
                <div className="text-sm">
                    <span className="font-medium">Quick edit</span>
                    <span className="ml-2 text-muted-foreground">
                        {fullName ?? '—'}
                    </span>
                </div>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onClose}
                >
                    Close
                </Button>
            </div>

            <div
                role="tablist"
                aria-label="Edit section"
                className="flex flex-wrap gap-1 border-b bg-muted/30 px-2 py-2"
            >
                {SECTIONS.map(({ id, label, icon: Icon }) => {
                    const isActive = activeTab === id;

                    return (
                        <button
                            key={id}
                            role="tab"
                            type="button"
                            aria-selected={isActive}
                            onClick={() => setActiveTab(id)}
                            className={cn(
                                'inline-flex items-center gap-2 rounded px-3 py-1.5 text-sm transition-colors',
                                isActive
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <Icon className="h-3.5 w-3.5" />
                            {label}
                        </button>
                    );
                })}
            </div>

            <div className="p-4">
                {loading && <SectionSkeleton />}

                {fetchError !== null && !loading && (
                    <div className="space-y-3 text-sm">
                        <p className="text-destructive">
                            Could not load profile: {fetchError}
                        </p>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={retryFetch}
                        >
                            Retry
                        </Button>
                    </div>
                )}

                {profile === null && !loading && fetchError === null && (
                    <p className="text-sm text-muted-foreground">
                        No payroll profile to edit yet.
                    </p>
                )}

                {profile !== undefined &&
                    profile !== null &&
                    !loading &&
                    fetchError === null &&
                    (activeTab === 'salary' ? (
                        <SalaryForm
                            profile={profile}
                            staffId={staffId}
                            employmentTypeOptions={employmentTypeOptions}
                            onSaved={handleSaved}
                        />
                    ) : activeTab === 'status' ? (
                        <StatusForm
                            profile={profile}
                            staffId={staffId}
                            onSaved={handleSaved}
                        />
                    ) : activeTab === 'gov_ids' ? (
                        <GovIdsForm
                            profile={profile}
                            staffId={staffId}
                            onSaved={handleSaved}
                        />
                    ) : (
                        <BankForm
                            profile={profile}
                            staffId={staffId}
                            onSaved={handleSaved}
                        />
                    ))}
            </div>
        </div>
    );
}

function SectionSkeleton() {
    return (
        <div className="space-y-4">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-2/3" />
        </div>
    );
}

interface SaveBarProps {
    processing: boolean;
    isDirty: boolean;
}

function SaveBar({ processing, isDirty }: SaveBarProps) {
    return (
        <div className="mt-4 flex justify-end">
            <Button type="submit" size="sm" disabled={processing || !isDirty}>
                {processing && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                Save
            </Button>
        </div>
    );
}

type SalaryFields = {
    basic_salary_centavos: number;
    employment_classification: EmploymentClassification;
    pay_frequency: PayFrequency;
    tax_status: string;
};

type StatusFields = {
    is_active: boolean;
    date_hired: string;
    date_terminated: string;
};

type GovIdFields = {
    tin: string;
    sss_number: string;
    philhealth_number: string;
    pagibig_number: string;
};

type BankFields = {
    bank_name: string;
    bank_account_number: string;
    bank_account_name: string;
};

interface SalaryFormProps {
    profile: EmployeeProfile;
    staffId: number;
    employmentTypeOptions: EmploymentTypeOption[];
    onSaved: () => void;
}

function SalaryForm({
    profile,
    staffId,
    employmentTypeOptions,
    onSaved,
}: SalaryFormProps) {
    const form = useForm<SalaryFields>({
        basic_salary_centavos: profile.basic_salary_centavos,
        employment_classification: profile.employment_classification,
        pay_frequency: profile.pay_frequency,
        tax_status: profile.tax_status ?? '',
    });
    const [salaryStr, setSalaryStr] = useState(() =>
        centavosToPesos(profile.basic_salary_centavos),
    );

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch(profileUpdate(staffId).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Salary updated');
                onSaved();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit} className="grid gap-4 md:grid-cols-2">
            <div className="grid gap-2">
                <Label htmlFor={`re-salary-${staffId}`}>Basic salary</Label>
                <div className="relative">
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                    >
                        ₱
                    </span>
                    <Input
                        id={`re-salary-${staffId}`}
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
                        onBlur={() =>
                            setSalaryStr(
                                centavosToPesos(
                                    form.data.basic_salary_centavos,
                                ),
                            )
                        }
                        className="pl-7 text-right tabular-nums"
                    />
                </div>
                <InputError message={form.errors.basic_salary_centavos} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`re-class-${staffId}`}>Employment type</Label>
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
                        id={`re-class-${staffId}`}
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {employmentTypeOptions.map((opt) => (
                            <SelectItem key={opt.value} value={opt.value}>
                                {opt.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={form.errors.employment_classification} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`re-freq-${staffId}`}>Pay frequency</Label>
                <Select
                    value={form.data.pay_frequency}
                    onValueChange={(v) =>
                        form.setData('pay_frequency', v as PayFrequency)
                    }
                >
                    <SelectTrigger id={`re-freq-${staffId}`} className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {PAY_FREQUENCY_OPTIONS.map((opt) => (
                            <SelectItem key={opt.value} value={opt.value}>
                                {opt.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={form.errors.pay_frequency} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`re-tax-${staffId}`}>Tax status</Label>
                <Input
                    id={`re-tax-${staffId}`}
                    type="text"
                    maxLength={8}
                    value={form.data.tax_status}
                    onChange={(e) => form.setData('tax_status', e.target.value)}
                    placeholder="e.g. S, ME1"
                />
                <InputError message={form.errors.tax_status} />
            </div>

            <div className="md:col-span-2">
                <SaveBar processing={form.processing} isDirty={form.isDirty} />
            </div>
        </form>
    );
}

interface StatusFormProps {
    profile: EmployeeProfile;
    staffId: number;
    onSaved: () => void;
}

function StatusForm({ profile, staffId, onSaved }: StatusFormProps) {
    const form = useForm<StatusFields>({
        is_active: profile.is_active,
        date_hired: profile.date_hired ?? '',
        date_terminated: profile.date_terminated ?? '',
    });

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch(profileUpdate(staffId).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Status updated');
                onSaved();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit} className="grid gap-4 md:grid-cols-2">
            <div className="flex items-center justify-between gap-4 rounded-md border p-3 md:col-span-2">
                <div className="space-y-0.5">
                    <Label htmlFor={`re-active-${staffId}`}>Active</Label>
                    <p className="text-xs text-muted-foreground">
                        Inactive profiles are excluded from payroll runs.
                    </p>
                </div>
                <Switch
                    id={`re-active-${staffId}`}
                    checked={form.data.is_active}
                    onCheckedChange={(checked) =>
                        form.setData('is_active', checked)
                    }
                />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`re-hired-${staffId}`}>Date hired</Label>
                <DatePicker
                    id={`re-hired-${staffId}`}
                    value={form.data.date_hired}
                    onChange={(v) => form.setData('date_hired', v)}
                    placeholder="Select date hired"
                    ariaInvalid={Boolean(form.errors.date_hired)}
                />
                <InputError message={form.errors.date_hired} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`re-terminated-${staffId}`}>
                    Date terminated
                </Label>
                <DatePicker
                    id={`re-terminated-${staffId}`}
                    value={form.data.date_terminated}
                    onChange={(v) => form.setData('date_terminated', v)}
                    placeholder="Select termination date"
                    ariaInvalid={Boolean(form.errors.date_terminated)}
                />
                <InputError message={form.errors.date_terminated} />
            </div>

            <div className="md:col-span-2">
                <SaveBar processing={form.processing} isDirty={form.isDirty} />
            </div>
        </form>
    );
}

interface GovIdsFormProps {
    profile: EmployeeProfile;
    staffId: number;
    onSaved: () => void;
}

function GovIdsForm({ profile, staffId, onSaved }: GovIdsFormProps) {
    const form = useForm<GovIdFields>({
        tin: profile.tin ?? '',
        sss_number: profile.sss_number ?? '',
        philhealth_number: profile.philhealth_number ?? '',
        pagibig_number: profile.pagibig_number ?? '',
    });

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch(profileUpdate(staffId).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Government IDs updated');
                onSaved();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit} className="grid gap-4 md:grid-cols-2">
            <div className="grid gap-2">
                <Label htmlFor={`re-tin-${staffId}`}>TIN</Label>
                <Input
                    id={`re-tin-${staffId}`}
                    type="text"
                    maxLength={32}
                    value={form.data.tin}
                    onChange={(e) => form.setData('tin', e.target.value)}
                    className="font-mono"
                />
                <InputError message={form.errors.tin} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`re-sss-${staffId}`}>SSS number</Label>
                <Input
                    id={`re-sss-${staffId}`}
                    type="text"
                    maxLength={32}
                    value={form.data.sss_number}
                    onChange={(e) => form.setData('sss_number', e.target.value)}
                    className="font-mono"
                />
                <InputError message={form.errors.sss_number} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`re-philhealth-${staffId}`}>
                    PhilHealth number
                </Label>
                <Input
                    id={`re-philhealth-${staffId}`}
                    type="text"
                    maxLength={32}
                    value={form.data.philhealth_number}
                    onChange={(e) =>
                        form.setData('philhealth_number', e.target.value)
                    }
                    className="font-mono"
                />
                <InputError message={form.errors.philhealth_number} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`re-pagibig-${staffId}`}>Pag-IBIG number</Label>
                <Input
                    id={`re-pagibig-${staffId}`}
                    type="text"
                    maxLength={32}
                    value={form.data.pagibig_number}
                    onChange={(e) =>
                        form.setData('pagibig_number', e.target.value)
                    }
                    className="font-mono"
                />
                <InputError message={form.errors.pagibig_number} />
            </div>

            <div className="md:col-span-2">
                <SaveBar processing={form.processing} isDirty={form.isDirty} />
            </div>
        </form>
    );
}

interface BankFormProps {
    profile: EmployeeProfile;
    staffId: number;
    onSaved: () => void;
}

function BankForm({ profile, staffId, onSaved }: BankFormProps) {
    const form = useForm<BankFields>({
        bank_name: profile.bank_name ?? '',
        bank_account_number: profile.bank_account_number ?? '',
        bank_account_name: profile.bank_account_name ?? '',
    });

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch(profileUpdate(staffId).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Bank account updated');
                onSaved();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit} className="grid gap-4 md:grid-cols-2">
            <div className="grid gap-2 md:col-span-2">
                <Label htmlFor={`re-bank-name-${staffId}`}>Bank name</Label>
                <Input
                    id={`re-bank-name-${staffId}`}
                    type="text"
                    maxLength={100}
                    value={form.data.bank_name}
                    onChange={(e) => form.setData('bank_name', e.target.value)}
                />
                <InputError message={form.errors.bank_name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`re-bank-no-${staffId}`}>Account number</Label>
                <Input
                    id={`re-bank-no-${staffId}`}
                    type="text"
                    maxLength={64}
                    value={form.data.bank_account_number}
                    onChange={(e) =>
                        form.setData('bank_account_number', e.target.value)
                    }
                    className="font-mono"
                />
                <InputError message={form.errors.bank_account_number} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`re-bank-acct-${staffId}`}>Account name</Label>
                <Input
                    id={`re-bank-acct-${staffId}`}
                    type="text"
                    maxLength={150}
                    value={form.data.bank_account_name}
                    onChange={(e) =>
                        form.setData('bank_account_name', e.target.value)
                    }
                />
                <InputError message={form.errors.bank_account_name} />
            </div>

            <div className="md:col-span-2">
                <SaveBar processing={form.processing} isDirty={form.isDirty} />
            </div>
        </form>
    );
}
