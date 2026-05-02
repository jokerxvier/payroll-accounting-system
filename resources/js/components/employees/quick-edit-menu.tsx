import { router, useForm } from '@inertiajs/react';
import { ChevronDown, Loader2, UserPlus } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import {
    json as profileJson,
    store as profileStore,
    update as profileUpdate,
} from '@/routes/employees/profile';
import type {
    EmployeeProfile,
    EmploymentClassification,
    EmploymentTypeOption,
    PayFrequency,
} from '@/types';

type Section = 'salary' | 'status' | 'gov_ids' | 'bank';

interface QuickEditMenuProps {
    staffId: number;
    fullName: string | null;
    hasProfile: boolean;
    employmentTypeOptions: EmploymentTypeOption[];
}

const PAY_FREQUENCY_OPTIONS: { value: PayFrequency; label: string }[] = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'semi_monthly', label: 'Semi-monthly' },
];

const SECTION_TITLES: Record<Section, { title: string; description: string }> =
    {
        salary: {
            title: 'Edit salary & classification',
            description:
                'Basic compensation, employment type, and pay frequency.',
        },
        status: {
            title: 'Edit status',
            description: 'Active flag and employment dates.',
        },
        gov_ids: {
            title: 'Edit government IDs',
            description: 'TIN, SSS, PhilHealth, and Pag-IBIG numbers.',
        },
        bank: {
            title: 'Edit bank account',
            description: 'Bank details used for payslip remittance.',
        },
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

export function QuickEditMenu({
    staffId,
    fullName,
    hasProfile,
    employmentTypeOptions,
}: QuickEditMenuProps) {
    const [activeSection, setActiveSection] = useState<Section | null>(null);
    const [profile, setProfile] = useState<EmployeeProfile | null | undefined>(
        undefined,
    );
    const [fetchError, setFetchError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (activeSection === null || profile !== undefined) {
            return;
        }

        let cancelled = false;
        // Setting loading/error state on effect entry is the canonical pattern
        // for fetch-triggered-by-prop. The cascading-render rule misfires here.
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
    }, [activeSection, profile, staffId]);

    const handleSetUpProfile = () => {
        router.post(
            profileStore(staffId).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        'Profile created. Re-open Quick edit to fill it in.',
                    ),
                onError: () => toast.error('Could not create profile'),
            },
        );
    };

    const handleClose = () => {
        setActiveSection(null);
    };

    const handleSaved = () => {
        setActiveSection(null);
        setProfile(undefined); // invalidate so a re-open re-fetches fresh data
    };

    const retryFetch = () => {
        setProfile(undefined);
        setFetchError(null);
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button size="sm" variant="outline">
                        Quick edit
                        <ChevronDown className="ml-1 h-3.5 w-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuLabel>Edit section</DropdownMenuLabel>
                    <DropdownMenuItem
                        disabled={!hasProfile}
                        onSelect={(e) => {
                            e.preventDefault();
                            setActiveSection('salary');
                        }}
                    >
                        Salary &amp; classification
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={!hasProfile}
                        onSelect={(e) => {
                            e.preventDefault();
                            setActiveSection('status');
                        }}
                    >
                        Status
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={!hasProfile}
                        onSelect={(e) => {
                            e.preventDefault();
                            setActiveSection('gov_ids');
                        }}
                    >
                        Government IDs
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={!hasProfile}
                        onSelect={(e) => {
                            e.preventDefault();
                            setActiveSection('bank');
                        }}
                    >
                        Bank account
                    </DropdownMenuItem>
                    {!hasProfile && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onSelect={handleSetUpProfile}>
                                <UserPlus className="mr-2 h-4 w-4" />
                                Set up payroll profile
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog
                open={activeSection !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        handleClose();
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    {activeSection !== null && (
                        <>
                            <DialogHeader>
                                <DialogTitle className="font-serif">
                                    {SECTION_TITLES[activeSection].title}
                                </DialogTitle>
                                <DialogDescription>
                                    {fullName ?? '—'} ·{' '}
                                    {SECTION_TITLES[activeSection].description}
                                </DialogDescription>
                            </DialogHeader>

                            {loading && <SectionSkeleton />}

                            {fetchError !== null && !loading && (
                                <div className="space-y-3 py-4 text-sm">
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

                            {profile !== undefined &&
                                profile !== null &&
                                !loading &&
                                fetchError === null && (
                                    <SectionForm
                                        section={activeSection}
                                        profile={profile}
                                        staffId={staffId}
                                        employmentTypeOptions={
                                            employmentTypeOptions
                                        }
                                        onSaved={handleSaved}
                                        onCancel={handleClose}
                                    />
                                )}

                            {profile === null &&
                                !loading &&
                                fetchError === null && (
                                    <p className="py-4 text-sm text-muted-foreground">
                                        No payroll profile to edit yet.
                                    </p>
                                )}
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function SectionSkeleton() {
    return (
        <div className="space-y-4 py-2">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-2/3" />
        </div>
    );
}

interface SectionFormProps {
    section: Section;
    profile: EmployeeProfile;
    staffId: number;
    employmentTypeOptions: EmploymentTypeOption[];
    onSaved: () => void;
    onCancel: () => void;
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

function SectionForm({
    section,
    profile,
    staffId,
    employmentTypeOptions,
    onSaved,
    onCancel,
}: SectionFormProps) {
    if (section === 'salary') {
        return (
            <SalaryForm
                profile={profile}
                staffId={staffId}
                employmentTypeOptions={employmentTypeOptions}
                onSaved={onSaved}
                onCancel={onCancel}
            />
        );
    }

    if (section === 'status') {
        return (
            <StatusForm
                profile={profile}
                staffId={staffId}
                onSaved={onSaved}
                onCancel={onCancel}
            />
        );
    }

    if (section === 'gov_ids') {
        return (
            <GovIdsForm
                profile={profile}
                staffId={staffId}
                onSaved={onSaved}
                onCancel={onCancel}
            />
        );
    }

    return (
        <BankForm
            profile={profile}
            staffId={staffId}
            onSaved={onSaved}
            onCancel={onCancel}
        />
    );
}

function FormFooter({
    processing,
    isDirty,
    onCancel,
}: {
    processing: boolean;
    isDirty: boolean;
    onCancel: () => void;
}) {
    return (
        <DialogFooter className="gap-2">
            <Button
                type="button"
                variant="outline"
                onClick={onCancel}
                disabled={processing}
            >
                Cancel
            </Button>
            <Button type="submit" disabled={processing || !isDirty}>
                {processing && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                Save
            </Button>
        </DialogFooter>
    );
}

function SalaryForm({
    profile,
    staffId,
    employmentTypeOptions,
    onSaved,
    onCancel,
}: Omit<SectionFormProps, 'section'>) {
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
        <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid gap-2">
                <Label htmlFor="qe-salary">Basic salary</Label>
                <div className="relative">
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                    >
                        ₱
                    </span>
                    <Input
                        id="qe-salary"
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
                <Label htmlFor="qe-classification">Employment type</Label>
                <Select
                    value={form.data.employment_classification}
                    onValueChange={(v) =>
                        form.setData(
                            'employment_classification',
                            v as EmploymentClassification,
                        )
                    }
                >
                    <SelectTrigger id="qe-classification" className="w-full">
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
                <Label htmlFor="qe-frequency">Pay frequency</Label>
                <Select
                    value={form.data.pay_frequency}
                    onValueChange={(v) =>
                        form.setData('pay_frequency', v as PayFrequency)
                    }
                >
                    <SelectTrigger id="qe-frequency" className="w-full">
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
                <Label htmlFor="qe-tax">Tax status</Label>
                <Input
                    id="qe-tax"
                    type="text"
                    maxLength={8}
                    value={form.data.tax_status}
                    onChange={(e) => form.setData('tax_status', e.target.value)}
                    placeholder="e.g. S, ME1"
                />
                <InputError message={form.errors.tax_status} />
            </div>

            <FormFooter
                processing={form.processing}
                isDirty={form.isDirty}
                onCancel={onCancel}
            />
        </form>
    );
}

function StatusForm({
    profile,
    staffId,
    onSaved,
    onCancel,
}: Omit<SectionFormProps, 'section' | 'employmentTypeOptions'>) {
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
        <form onSubmit={handleSubmit} className="space-y-4">
            <div className="flex items-center justify-between gap-4 rounded-md border p-3">
                <div className="space-y-0.5">
                    <Label htmlFor="qe-active">Active</Label>
                    <p className="text-xs text-muted-foreground">
                        Inactive profiles are excluded from payroll runs.
                    </p>
                </div>
                <Switch
                    id="qe-active"
                    checked={form.data.is_active}
                    onCheckedChange={(checked) =>
                        form.setData('is_active', checked)
                    }
                />
            </div>
            <InputError message={form.errors.is_active} />

            <div className="grid gap-2">
                <Label htmlFor="qe-hired">Date hired</Label>
                <DatePicker
                    id="qe-hired"
                    value={form.data.date_hired}
                    onChange={(v) => form.setData('date_hired', v)}
                    placeholder="Select date hired"
                    ariaInvalid={Boolean(form.errors.date_hired)}
                />
                <InputError message={form.errors.date_hired} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="qe-terminated">Date terminated</Label>
                <DatePicker
                    id="qe-terminated"
                    value={form.data.date_terminated}
                    onChange={(v) => form.setData('date_terminated', v)}
                    placeholder="Select termination date"
                    ariaInvalid={Boolean(form.errors.date_terminated)}
                />
                <InputError message={form.errors.date_terminated} />
            </div>

            <FormFooter
                processing={form.processing}
                isDirty={form.isDirty}
                onCancel={onCancel}
            />
        </form>
    );
}

function GovIdsForm({
    profile,
    staffId,
    onSaved,
    onCancel,
}: Omit<SectionFormProps, 'section' | 'employmentTypeOptions'>) {
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
        <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid gap-2">
                <Label htmlFor="qe-tin">TIN</Label>
                <Input
                    id="qe-tin"
                    type="text"
                    maxLength={32}
                    value={form.data.tin}
                    onChange={(e) => form.setData('tin', e.target.value)}
                    className="font-mono"
                />
                <InputError message={form.errors.tin} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="qe-sss">SSS number</Label>
                <Input
                    id="qe-sss"
                    type="text"
                    maxLength={32}
                    value={form.data.sss_number}
                    onChange={(e) => form.setData('sss_number', e.target.value)}
                    className="font-mono"
                />
                <InputError message={form.errors.sss_number} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="qe-philhealth">PhilHealth number</Label>
                <Input
                    id="qe-philhealth"
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
                <Label htmlFor="qe-pagibig">Pag-IBIG number</Label>
                <Input
                    id="qe-pagibig"
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

            <FormFooter
                processing={form.processing}
                isDirty={form.isDirty}
                onCancel={onCancel}
            />
        </form>
    );
}

function BankForm({
    profile,
    staffId,
    onSaved,
    onCancel,
}: Omit<SectionFormProps, 'section' | 'employmentTypeOptions'>) {
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
        <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid gap-2">
                <Label htmlFor="qe-bank-name">Bank name</Label>
                <Input
                    id="qe-bank-name"
                    type="text"
                    maxLength={100}
                    value={form.data.bank_name}
                    onChange={(e) => form.setData('bank_name', e.target.value)}
                />
                <InputError message={form.errors.bank_name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="qe-bank-acct-no">Account number</Label>
                <Input
                    id="qe-bank-acct-no"
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
                <Label htmlFor="qe-bank-acct-name">Account name</Label>
                <Input
                    id="qe-bank-acct-name"
                    type="text"
                    maxLength={150}
                    value={form.data.bank_account_name}
                    onChange={(e) =>
                        form.setData('bank_account_name', e.target.value)
                    }
                />
                <InputError message={form.errors.bank_account_name} />
            </div>

            <FormFooter
                processing={form.processing}
                isDirty={form.isDirty}
                onCancel={onCancel}
            />
        </form>
    );
}
