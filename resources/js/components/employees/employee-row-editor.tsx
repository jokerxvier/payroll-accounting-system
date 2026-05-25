import { router, useForm } from '@inertiajs/react';
import {
    Banknote,
    IdCard,
    Landmark,
    Loader2,
    MinusCircle,
    PlusCircle,
    ShieldCheck,
    Sliders,
    ToggleRight,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ComponentType, FormEvent, ReactNode } from 'react';
import { toast } from 'sonner';
import { destroy as allowanceDestroy } from '@/actions/App/Http/Controllers/Employees/EmployeeAllowanceController';
import { destroy as deductionDestroy } from '@/actions/App/Http/Controllers/Employees/EmployeeDeductionController';
import { destroy as loanDestroy } from '@/actions/App/Http/Controllers/Employees/EmployeeLoanController';
import { destroy as adjustmentDestroy } from '@/actions/App/Http/Controllers/Employees/PayrollAdjustmentController';
import { AdjustmentEditSheet } from '@/components/employees/adjustment-edit-sheet';
import { AdjustmentsCard } from '@/components/employees/adjustments-card';
import { AllowanceEditSheet } from '@/components/employees/allowance-edit-sheet';
import { AllowancesCard } from '@/components/employees/allowances-card';
import { DeductionEditSheet } from '@/components/employees/deduction-edit-sheet';
import { DeductionsCard } from '@/components/employees/deductions-card';
import { LoanEditSheet } from '@/components/employees/loan-edit-sheet';
import { LoansCard } from '@/components/employees/loans-card';
import InputError from '@/components/input-error';
import { Money } from '@/components/money';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import {
    json as profileJson,
    update as profileUpdate,
} from '@/routes/employees/profile';
import type {
    AllowanceRef,
    AvailableContribution,
    DeductionTypeRef,
    EmployeeAllowanceRow,
    EmployeeDeductionRow,
    EmployeeLoanRow,
    EmployeeProfile,
    EmploymentClassification,
    EmploymentTypeOption,
    PayFrequency,
    PayrollAdjustmentRow,
} from '@/types';

type Section =
    | 'salary'
    | 'status'
    | 'gov_ids'
    | 'bank'
    | 'exemptions'
    | 'deductions'
    | 'allowances'
    | 'loans'
    | 'adjustments';

interface EmployeeRowEditorProps {
    staffId: number;
    fullName: string | null;
    employmentTypeOptions: EmploymentTypeOption[];
    onClose: () => void;
}

/**
 * Shape of `/employees/{staffId}/profile/json`. Mirrors EmployeeController::profileJson
 * and `pages/employees/show.tsx` so the inline row editor can render the same
 * four subscription cards (deductions, allowances, loans, adjustments) without
 * navigating to the Show page.
 */
interface ProfileJsonResponse {
    profile: EmployeeProfile | null;
    deductions: EmployeeDeductionRow[];
    allowances: EmployeeAllowanceRow[];
    loans: EmployeeLoanRow[];
    pendingAdjustments: PayrollAdjustmentRow[];
    deductionTypeOptions: DeductionTypeRef[];
    allowanceOptions: AllowanceRef[];
    availableContributions: AvailableContribution[];
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
    { id: 'exemptions', label: 'Statutory exemptions', icon: ShieldCheck },
    { id: 'deductions', label: 'Deductions', icon: MinusCircle },
    { id: 'allowances', label: 'Allowances', icon: PlusCircle },
    { id: 'loans', label: 'Loans', icon: Landmark },
    { id: 'adjustments', label: 'Adjustments', icon: Sliders },
];

const PAY_FREQUENCY_OPTIONS: { value: PayFrequency; label: string }[] = [
    { value: 'monthly', label: 'Monthly' },
    { value: 'semi_monthly', label: 'Semi-monthly' },
];

type PendingDelete =
    | { kind: 'deduction'; row: EmployeeDeductionRow }
    | { kind: 'allowance'; row: EmployeeAllowanceRow }
    | { kind: 'loan'; row: EmployeeLoanRow }
    | { kind: 'adjustment'; row: PayrollAdjustmentRow };

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
    const [profileData, setProfileData] = useState<
        ProfileJsonResponse | null | undefined
    >(undefined);
    const [fetchError, setFetchError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    // Sheet state for the four Week 7 subscription tables. Each sheet has an
    // `open` flag plus the row currently being edited (undefined → create
    // mode). Mirrors the pattern in `pages/employees/show.tsx`.
    const [deductionSheetOpen, setDeductionSheetOpen] = useState(false);
    const [editingDeduction, setEditingDeduction] = useState<
        EmployeeDeductionRow | undefined
    >(undefined);

    const [allowanceSheetOpen, setAllowanceSheetOpen] = useState(false);
    const [editingAllowance, setEditingAllowance] = useState<
        EmployeeAllowanceRow | undefined
    >(undefined);

    const [loanSheetOpen, setLoanSheetOpen] = useState(false);
    const [editingLoan, setEditingLoan] = useState<EmployeeLoanRow | undefined>(
        undefined,
    );

    const [adjustmentSheetOpen, setAdjustmentSheetOpen] = useState(false);
    const [editingAdjustment, setEditingAdjustment] = useState<
        PayrollAdjustmentRow | undefined
    >(undefined);

    // Single shared delete-confirmation state. Mirrors the pattern in
    // `pages/employees/show.tsx` so all four card types route through one
    // <AlertDialog>. After a successful delete, the lazy profile JSON is
    // invalidated so the cards re-render with the row removed.
    const [pendingDelete, setPendingDelete] = useState<PendingDelete | null>(
        null,
    );
    const [isDeleting, setIsDeleting] = useState(false);

    const handleConfirmDelete = (): void => {
        if (pendingDelete === null) {
            return;
        }

        const url = resolveDestroyUrl(staffId, pendingDelete);
        const successMessage = resolveDeleteSuccessMessage(pendingDelete);
        const errorMessage = resolveDeleteErrorMessage(pendingDelete);

        setIsDeleting(true);

        router.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(successMessage);
                setPendingDelete(null);
                // Invalidate the cached profile so the cards refetch.
                setProfileData(undefined);
            },
            onError: () => {
                toast.error(errorMessage);
            },
            onFinish: () => {
                setIsDeleting(false);
            },
        });
    };

    useEffect(() => {
        if (profileData !== undefined) {
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

                return (await res.json()) as ProfileJsonResponse;
            })
            .then((data) => {
                if (!cancelled) {
                    setProfileData(data);
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
    }, [profileData, staffId]);

    const handleSaved = () => {
        // Invalidate the cached profile so the row refetches and shows the
        // newly-saved values. Do NOT auto-close the row — the user closes
        // manually via the X button so they can edit multiple tabs in one
        // session without re-expanding.
        setProfileData(undefined);
    };

    const retryFetch = () => {
        setProfileData(undefined);
        setFetchError(null);
    };

    /**
     * Wrap the sheet's onOpenChange so the row editor refetches its profile
     * payload whenever a sheet closes. The sheets ship with `preserveState`
     * and don't expose an explicit onSuccess callback, so we conservatively
     * refetch on every close. Cancel-without-changes triggers a small extra
     * GET — acceptable trade-off vs. modifying the shared edit-sheet contracts.
     */
    const wrapSheetOpenChange =
        (
            setOpen: (open: boolean) => void,
            clearEditing: () => void,
        ): ((open: boolean) => void) =>
        (open) => {
            setOpen(open);

            if (!open) {
                clearEditing();
                setProfileData(undefined);
            }
        };

    const loadedData = profileData ?? null;
    const profile = loadedData?.profile ?? null;

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

                {loadedData !== null &&
                    profile === null &&
                    !loading &&
                    fetchError === null && (
                        <p className="text-sm text-muted-foreground">
                            No payroll profile to edit yet.
                        </p>
                    )}

                {loadedData !== null &&
                    profile !== null &&
                    !loading &&
                    fetchError === null && (
                        <ProfileTabContent
                            activeTab={activeTab}
                            staffId={staffId}
                            profile={profile}
                            data={loadedData}
                            employmentTypeOptions={employmentTypeOptions}
                            onSaved={handleSaved}
                            onAddDeduction={() => {
                                setEditingDeduction(undefined);
                                setDeductionSheetOpen(true);
                            }}
                            onEditDeduction={(row) => {
                                setEditingDeduction(row);
                                setDeductionSheetOpen(true);
                            }}
                            onAddAllowance={() => {
                                setEditingAllowance(undefined);
                                setAllowanceSheetOpen(true);
                            }}
                            onEditAllowance={(row) => {
                                setEditingAllowance(row);
                                setAllowanceSheetOpen(true);
                            }}
                            onAddLoan={() => {
                                setEditingLoan(undefined);
                                setLoanSheetOpen(true);
                            }}
                            onEditLoan={(row) => {
                                setEditingLoan(row);
                                setLoanSheetOpen(true);
                            }}
                            onAddAdjustment={() => {
                                setEditingAdjustment(undefined);
                                setAdjustmentSheetOpen(true);
                            }}
                            onEditAdjustment={(row) => {
                                setEditingAdjustment(row);
                                setAdjustmentSheetOpen(true);
                            }}
                            onDeleteDeduction={(row) =>
                                setPendingDelete({ kind: 'deduction', row })
                            }
                            onDeleteAllowance={(row) =>
                                setPendingDelete({ kind: 'allowance', row })
                            }
                            onDeleteLoan={(row) =>
                                setPendingDelete({ kind: 'loan', row })
                            }
                            onDeleteAdjustment={(row) =>
                                setPendingDelete({ kind: 'adjustment', row })
                            }
                        />
                    )}
            </div>

            {loadedData !== null && profile !== null && (
                <>
                    <DeductionEditSheet
                        open={deductionSheetOpen}
                        onOpenChange={wrapSheetOpenChange(
                            setDeductionSheetOpen,
                            () => setEditingDeduction(undefined),
                        )}
                        lmsStaffId={staffId}
                        deduction={editingDeduction}
                        deductionTypeOptions={loadedData.deductionTypeOptions}
                    />
                    <AllowanceEditSheet
                        open={allowanceSheetOpen}
                        onOpenChange={wrapSheetOpenChange(
                            setAllowanceSheetOpen,
                            () => setEditingAllowance(undefined),
                        )}
                        lmsStaffId={staffId}
                        allowance={editingAllowance}
                        allowanceOptions={loadedData.allowanceOptions}
                    />
                    <LoanEditSheet
                        open={loanSheetOpen}
                        onOpenChange={wrapSheetOpenChange(
                            setLoanSheetOpen,
                            () => setEditingLoan(undefined),
                        )}
                        lmsStaffId={staffId}
                        loan={editingLoan}
                    />
                    <AdjustmentEditSheet
                        open={adjustmentSheetOpen}
                        onOpenChange={wrapSheetOpenChange(
                            setAdjustmentSheetOpen,
                            () => setEditingAdjustment(undefined),
                        )}
                        lmsStaffId={staffId}
                        adjustment={editingAdjustment}
                    />
                </>
            )}

            <AlertDialog
                open={pendingDelete !== null}
                onOpenChange={(open) => {
                    if (!open && !isDeleting) {
                        setPendingDelete(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {pendingDelete
                                ? resolveDeleteTitle(pendingDelete)
                                : ''}
                        </AlertDialogTitle>
                        <AlertDialogDescription asChild>
                            <div className="space-y-2 text-sm text-muted-foreground">
                                {pendingDelete &&
                                    resolveDeleteDescription(pendingDelete)}
                            </div>
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            onClick={(event) => {
                                event.preventDefault();
                                handleConfirmDelete();
                            }}
                            disabled={isDeleting}
                        >
                            {isDeleting ? 'Deleting…' : 'Delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

interface ProfileTabContentProps {
    activeTab: Section;
    staffId: number;
    profile: EmployeeProfile;
    data: ProfileJsonResponse;
    employmentTypeOptions: EmploymentTypeOption[];
    onSaved: () => void;
    onAddDeduction: () => void;
    onEditDeduction: (row: EmployeeDeductionRow) => void;
    onAddAllowance: () => void;
    onEditAllowance: (row: EmployeeAllowanceRow) => void;
    onAddLoan: () => void;
    onEditLoan: (row: EmployeeLoanRow) => void;
    onAddAdjustment: () => void;
    onEditAdjustment: (row: PayrollAdjustmentRow) => void;
    onDeleteDeduction: (row: EmployeeDeductionRow) => void;
    onDeleteAllowance: (row: EmployeeAllowanceRow) => void;
    onDeleteLoan: (row: EmployeeLoanRow) => void;
    onDeleteAdjustment: (row: PayrollAdjustmentRow) => void;
}

function ProfileTabContent({
    activeTab,
    staffId,
    profile,
    data,
    employmentTypeOptions,
    onSaved,
    onAddDeduction,
    onEditDeduction,
    onAddAllowance,
    onEditAllowance,
    onAddLoan,
    onEditLoan,
    onAddAdjustment,
    onEditAdjustment,
    onDeleteDeduction,
    onDeleteAllowance,
    onDeleteLoan,
    onDeleteAdjustment,
}: ProfileTabContentProps) {
    switch (activeTab) {
        case 'salary':
            return (
                <SalaryForm
                    profile={profile}
                    staffId={staffId}
                    employmentTypeOptions={employmentTypeOptions}
                    onSaved={onSaved}
                />
            );
        case 'status':
            return (
                <StatusForm
                    profile={profile}
                    staffId={staffId}
                    onSaved={onSaved}
                />
            );
        case 'gov_ids':
            return (
                <GovIdsForm
                    profile={profile}
                    staffId={staffId}
                    onSaved={onSaved}
                />
            );
        case 'bank':
            return (
                <BankForm
                    profile={profile}
                    staffId={staffId}
                    onSaved={onSaved}
                />
            );
        case 'exemptions':
            return (
                <ExemptionsForm
                    profile={profile}
                    staffId={staffId}
                    availableContributions={data.availableContributions}
                    onSaved={onSaved}
                />
            );
        case 'deductions':
            return (
                <DeductionsCard
                    deductions={data.deductions}
                    className="lg:col-span-1"
                    onAdd={onAddDeduction}
                    onEdit={onEditDeduction}
                    onDelete={onDeleteDeduction}
                />
            );
        case 'allowances':
            return (
                <AllowancesCard
                    allowances={data.allowances}
                    className="lg:col-span-1"
                    onAdd={onAddAllowance}
                    onEdit={onEditAllowance}
                    onDelete={onDeleteAllowance}
                />
            );
        case 'loans':
            return (
                <LoansCard
                    loans={data.loans}
                    className="lg:col-span-1"
                    onAdd={onAddLoan}
                    onEdit={onEditLoan}
                    onDelete={onDeleteLoan}
                />
            );
        case 'adjustments':
            return (
                <AdjustmentsCard
                    adjustments={data.pendingAdjustments}
                    className="lg:col-span-1"
                    onAdd={onAddAdjustment}
                    onEdit={onEditAdjustment}
                    onDelete={onDeleteAdjustment}
                />
            );
    }
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

type ExemptionFields = {
    exempted_contribution_codes: string[];
};

interface ExemptionsFormProps {
    profile: EmployeeProfile;
    staffId: number;
    availableContributions: AvailableContribution[];
    onSaved: () => void;
}

function ExemptionsForm({
    profile,
    staffId,
    availableContributions,
    onSaved,
}: ExemptionsFormProps) {
    // Defensive `?? []` — older payloads predating the column may still
    // arrive with the field undefined (mirrors edit-sheet.tsx).
    const form = useForm<ExemptionFields>({
        exempted_contribution_codes: profile.exempted_contribution_codes ?? [],
    });

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
        form.patch(profileUpdate(staffId).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Statutory exemptions updated');
                onSaved();
            },
        });
    };

    if (availableContributions.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No active statutory contributions are configured. Add entries
                under Catalog → Contribution tables to manage exemptions here.
            </p>
        );
    }

    const headingId = `re-exemptions-heading-${staffId}`;
    const descriptionId = `re-exemptions-description-${staffId}`;

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <section className="space-y-3" aria-labelledby={headingId}>
                <div className="space-y-1">
                    <h3 id={headingId} className="text-sm font-medium">
                        Statutory exemptions
                    </h3>
                    <p
                        id={descriptionId}
                        className="text-xs text-muted-foreground"
                    >
                        Selected contributions will not be deducted from this
                        employee's payroll. Use sparingly — most employees
                        should be subscribed to all active statutory
                        contributions.
                    </p>
                </div>

                <ul className="space-y-2" aria-describedby={descriptionId}>
                    {availableContributions.map((contribution) => {
                        const checkboxId = `re-exemption-${staffId}-${contribution.code}`;
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
                                        checked={isChecked}
                                        onCheckedChange={(checked) =>
                                            toggleExemption(
                                                contribution.code,
                                                checked === true,
                                            )
                                        }
                                        className="mt-0.5"
                                    />
                                    <div className="flex-1 space-y-0.5">
                                        <span className="font-mono text-sm">
                                            {contribution.code}
                                        </span>
                                        <p className="text-xs text-muted-foreground">
                                            {contribution.label}
                                        </p>
                                    </div>
                                </Label>
                            </li>
                        );
                    })}
                </ul>
                <InputError message={form.errors.exempted_contribution_codes} />
            </section>

            <SaveBar processing={form.processing} isDirty={form.isDirty} />
        </form>
    );
}

function resolveDestroyUrl(staffId: number, pending: PendingDelete): string {
    switch (pending.kind) {
        case 'deduction':
            return deductionDestroy({
                staffId,
                employeeDeduction: pending.row.id,
            }).url;
        case 'allowance':
            return allowanceDestroy({
                staffId,
                employeeAllowance: pending.row.id,
            }).url;
        case 'loan':
            return loanDestroy({
                staffId,
                employeeLoan: pending.row.id,
            }).url;
        case 'adjustment':
            return adjustmentDestroy({
                staffId,
                payrollAdjustment: pending.row.id,
            }).url;
    }
}

function resolveDeleteTitle(pending: PendingDelete): string {
    switch (pending.kind) {
        case 'deduction':
            return 'Delete custom deduction?';
        case 'allowance':
            return 'Delete allowance?';
        case 'loan':
            return 'Delete loan?';
        case 'adjustment':
            return 'Delete adjustment?';
    }
}

function resolveDeleteDescription(pending: PendingDelete): ReactNode {
    switch (pending.kind) {
        case 'deduction':
            return (
                <p>
                    This removes{' '}
                    <span className="font-medium text-foreground">
                        {pending.row.deduction_type.name}
                    </span>{' '}
                    from this employee's payroll. The history is preserved in
                    the audit log.
                </p>
            );
        case 'allowance':
            return (
                <p>
                    This removes{' '}
                    <span className="font-medium text-foreground">
                        {pending.row.allowance.name}
                    </span>{' '}
                    from this employee's payroll. The history is preserved in
                    the audit log.
                </p>
            );
        case 'loan':
            return (
                <>
                    <p>
                        This removes loan{' '}
                        <span className="font-mono text-foreground">
                            {pending.row.code}
                        </span>{' '}
                        from this employee's payroll. The history is preserved
                        in the audit log.
                    </p>
                    {pending.row.outstanding_balance_centavos > 0 && (
                        <p className="text-warning">
                            <Money
                                amount={
                                    pending.row.outstanding_balance_centavos /
                                    100
                                }
                            />{' '}
                            is still outstanding on this loan.
                        </p>
                    )}
                </>
            );
        case 'adjustment':
            return (
                <p>
                    This removes the adjustment{' '}
                    <span className="font-medium text-foreground">
                        {pending.row.label}
                    </span>{' '}
                    from this employee's payroll. The history is preserved in
                    the audit log.
                </p>
            );
    }
}

function resolveDeleteSuccessMessage(pending: PendingDelete): string {
    switch (pending.kind) {
        case 'deduction':
            return `Removed ${pending.row.deduction_type.name}.`;
        case 'allowance':
            return `Removed ${pending.row.allowance.name}.`;
        case 'loan':
            return `Removed loan ${pending.row.code}.`;
        case 'adjustment':
            return `Removed ${pending.row.label}.`;
    }
}

function resolveDeleteErrorMessage(pending: PendingDelete): string {
    switch (pending.kind) {
        case 'deduction':
            return 'Could not delete this deduction.';
        case 'allowance':
            return 'Could not delete this allowance.';
        case 'loan':
            return 'Could not delete this loan.';
        case 'adjustment':
            return 'Could not delete this adjustment.';
    }
}
