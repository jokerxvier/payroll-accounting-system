import { Head, router } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
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
import { EmployeeEditSheet } from '@/components/employees/edit-sheet';
import { LoanEditSheet } from '@/components/employees/loan-edit-sheet';
import { LoansCard } from '@/components/employees/loans-card';
import { EmptyState } from '@/components/empty-state';
import { InlineMoneyEdit } from '@/components/inline-money-edit';
import { Money } from '@/components/money';
import { PageHeader } from '@/components/page-header';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { index as employeesIndex } from '@/routes/employees';
import {
    store as profileStore,
    update as profileUpdate,
} from '@/routes/employees/profile';
import type {
    AllowanceRef,
    DeductionTypeRef,
    EmployeeAllowanceRow,
    EmployeeDeductionRow,
    EmployeeDetail,
    EmployeeLoanRow,
    EmployeeProfile,
    EmploymentTypeOption,
    PayFrequency,
    PayrollAdjustmentRow,
} from '@/types';

interface Props {
    employee: EmployeeDetail;
    employmentTypeOptions: EmploymentTypeOption[];
    deductions: EmployeeDeductionRow[];
    allowances: EmployeeAllowanceRow[];
    loans: EmployeeLoanRow[];
    pendingAdjustments: PayrollAdjustmentRow[];
    deductionTypeOptions: DeductionTypeRef[];
    allowanceOptions: AllowanceRef[];
}

const PAY_FREQUENCY_LABEL: Record<PayFrequency, string> = {
    monthly: 'Monthly',
    semi_monthly: 'Semi-monthly',
};

type PendingDelete =
    | { kind: 'deduction'; row: EmployeeDeductionRow }
    | { kind: 'allowance'; row: EmployeeAllowanceRow }
    | { kind: 'loan'; row: EmployeeLoanRow }
    | { kind: 'adjustment'; row: PayrollAdjustmentRow };

const DATE_FORMATTER = new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});

export default function EmployeesShow({
    employee,
    employmentTypeOptions,
    deductions,
    allowances,
    loans,
    pendingAdjustments,
    deductionTypeOptions,
    allowanceOptions,
}: Props) {
    const [editOpen, setEditOpen] = useState(false);

    // Sheet state for the four Week 7 subscription tables. Each sheet has an
    // `open` flag plus the row currently being edited (undefined → create
    // mode). The parent owns this so opening a fresh "Add" never inherits
    // stale row data from a previous edit pass.
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

    // Single shared delete-confirmation state. The discriminated union keeps
    // one `<AlertDialog>` rendered for all four card types, so the dialog copy,
    // confirm handler, and Wayfinder destroy URL all derive from `pendingDelete.kind`.
    const [pendingDelete, setPendingDelete] = useState<PendingDelete | null>(
        null,
    );
    const [isDeleting, setIsDeleting] = useState(false);

    const handleConfirmDelete = (): void => {
        if (pendingDelete === null) {
            return;
        }

        const staffId = employee.lms_staff_id;
        const url = resolveDestroyUrl(staffId, pendingDelete);
        const successMessage = resolveDeleteSuccessMessage(pendingDelete);
        const errorMessage = resolveDeleteErrorMessage(pendingDelete);

        setIsDeleting(true);

        router.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(successMessage);
                setPendingDelete(null);
            },
            onError: () => {
                toast.error(errorMessage);
            },
            onFinish: () => {
                setIsDeleting(false);
            },
        });
    };

    const description =
        [
            employee.staff_no,
            employee.designation?.title,
            employee.department?.name,
        ]
            .filter(Boolean)
            .join(' · ') || '—';

    const hasProfile = employee.profile !== null;

    return (
        <>
            <Head title={`${employee.full_name ?? 'Employee'} · Employee`} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="EMPLOYEE"
                    title={employee.full_name ?? '—'}
                    description={description}
                    actions={
                        hasProfile ? (
                            <Button onClick={() => setEditOpen(true)}>
                                Edit profile
                            </Button>
                        ) : undefined
                    }
                />

                <IdentityCard employee={employee} />

                {employee.profile === null ? (
                    <NoProfileState staffId={employee.lms_staff_id} />
                ) : (
                    <ProfileSection
                        profile={employee.profile}
                        staffId={employee.lms_staff_id}
                        deductions={deductions}
                        allowances={allowances}
                        loans={loans}
                        adjustments={pendingAdjustments}
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

            {hasProfile && (
                <EmployeeEditSheet
                    employee={employee}
                    employmentTypeOptions={employmentTypeOptions}
                    open={editOpen}
                    onOpenChange={setEditOpen}
                />
            )}

            {hasProfile && (
                <>
                    <DeductionEditSheet
                        open={deductionSheetOpen}
                        onOpenChange={setDeductionSheetOpen}
                        lmsStaffId={employee.lms_staff_id}
                        deduction={editingDeduction}
                        deductionTypeOptions={deductionTypeOptions}
                    />
                    <AllowanceEditSheet
                        open={allowanceSheetOpen}
                        onOpenChange={setAllowanceSheetOpen}
                        lmsStaffId={employee.lms_staff_id}
                        allowance={editingAllowance}
                        allowanceOptions={allowanceOptions}
                    />
                    <LoanEditSheet
                        open={loanSheetOpen}
                        onOpenChange={setLoanSheetOpen}
                        lmsStaffId={employee.lms_staff_id}
                        loan={editingLoan}
                    />
                    <AdjustmentEditSheet
                        open={adjustmentSheetOpen}
                        onOpenChange={setAdjustmentSheetOpen}
                        lmsStaffId={employee.lms_staff_id}
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
        </>
    );
}

EmployeesShow.layout = {
    breadcrumbs: [{ title: 'Employees', href: employeesIndex().url }],
};

function IdentityCard({ employee }: { employee: EmployeeDetail }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="font-serif text-lg">
                    Identity (from LMS)
                </CardTitle>
                <CardDescription>
                    Edits to identity happen in the LMS, not in payroll.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Full name" value={employee.full_name} />
                    <Field label="Staff no." value={employee.staff_no} mono />
                    <Field label="Email" value={employee.email} />
                    <Field label="Role" value={employee.role?.name ?? null} />
                    <Field
                        label="Department"
                        value={employee.department?.name ?? null}
                    />
                    <Field
                        label="Designation"
                        value={employee.designation?.title ?? null}
                    />
                </dl>
            </CardContent>
        </Card>
    );
}

function NoProfileState({ staffId }: { staffId: number }) {
    const [submitting, setSubmitting] = useState(false);

    const handleCreate = () => {
        setSubmitting(true);
        router.post(
            profileStore(staffId).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setSubmitting(false),
            },
        );
    };

    return (
        <Card>
            <CardContent className="pt-6">
                <EmptyState
                    icon={UserPlus}
                    title="No payroll profile yet"
                    description="Set up a payroll profile for this employee to begin computing payslips."
                    action={
                        <Button onClick={handleCreate} disabled={submitting}>
                            Set up payroll profile
                        </Button>
                    }
                />
            </CardContent>
        </Card>
    );
}

interface ProfileSectionProps {
    profile: EmployeeProfile;
    staffId: number;
    deductions: EmployeeDeductionRow[];
    allowances: EmployeeAllowanceRow[];
    loans: EmployeeLoanRow[];
    adjustments: PayrollAdjustmentRow[];
    onAddDeduction: () => void;
    onEditDeduction: (row: EmployeeDeductionRow) => void;
    onAddAllowance: () => void;
    onEditAllowance: (row: EmployeeAllowanceRow) => void;
    onAddLoan: () => void;
    onEditLoan: (loan: EmployeeLoanRow) => void;
    onAddAdjustment: () => void;
    onEditAdjustment: (row: PayrollAdjustmentRow) => void;
    onDeleteDeduction: (row: EmployeeDeductionRow) => void;
    onDeleteAllowance: (row: EmployeeAllowanceRow) => void;
    onDeleteLoan: (loan: EmployeeLoanRow) => void;
    onDeleteAdjustment: (row: PayrollAdjustmentRow) => void;
}

function ProfileSection({
    profile,
    staffId,
    deductions,
    allowances,
    loans,
    adjustments,
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
}: ProfileSectionProps) {
    return (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <SalaryCard profile={profile} staffId={staffId} />
            <StatusCard profile={profile} />
            <GovernmentIdsCard profile={profile} />
            <BankCard profile={profile} />
            <DeductionsCard
                deductions={deductions}
                onAdd={onAddDeduction}
                onEdit={onEditDeduction}
                onDelete={onDeleteDeduction}
            />
            <AllowancesCard
                allowances={allowances}
                onAdd={onAddAllowance}
                onEdit={onEditAllowance}
                onDelete={onDeleteAllowance}
            />
            <LoansCard
                loans={loans}
                onAdd={onAddLoan}
                onEdit={onEditLoan}
                onDelete={onDeleteLoan}
            />
            <AdjustmentsCard
                adjustments={adjustments}
                onAdd={onAddAdjustment}
                onEdit={onEditAdjustment}
                onDelete={onDeleteAdjustment}
            />
        </div>
    );
}

function SalaryCard({
    profile,
    staffId,
}: {
    profile: EmployeeProfile;
    staffId: number;
}) {
    const handleSalarySave = (centavos: number) =>
        new Promise<void>((resolve, reject) => {
            router.patch(
                profileUpdate(staffId).url,
                { basic_salary_centavos: centavos },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        toast.success('Salary updated');
                        resolve();
                    },
                    onError: (errors) => {
                        const message =
                            errors.basic_salary_centavos ?? 'Could not save';
                        toast.error(message);
                        reject(new Error(message));
                    },
                },
            );
        });

    return (
        <Card>
            <CardHeader>
                <CardTitle className="font-serif text-lg">
                    Salary &amp; classification
                </CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="space-y-1">
                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                            Basic salary
                        </dt>
                        <dd className="font-serif text-2xl font-semibold tabular-nums">
                            <InlineMoneyEdit
                                valueCentavos={profile.basic_salary_centavos}
                                label="Basic salary"
                                onSave={handleSalarySave}
                            />
                        </dd>
                    </div>
                    <Field
                        label="Pay frequency"
                        value={PAY_FREQUENCY_LABEL[profile.pay_frequency]}
                    />
                    <Field
                        label="Employment type"
                        value={profile.employment_classification.replace(
                            '_',
                            ' ',
                        )}
                        capitalize
                    />
                    <Field label="Tax status" value={profile.tax_status} />
                </dl>
            </CardContent>
        </Card>
    );
}

function StatusCard({ profile }: { profile: EmployeeProfile }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="font-serif text-lg">Status</CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="space-y-1">
                        <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                            Active
                        </dt>
                        <dd>
                            {profile.is_active ? (
                                <Badge className="bg-success/15 text-success hover:bg-success/15">
                                    Active
                                </Badge>
                            ) : (
                                <Badge className="bg-warning/15 text-warning hover:bg-warning/15">
                                    Inactive
                                </Badge>
                            )}
                        </dd>
                    </div>
                    <Field
                        label="Date hired"
                        value={formatDate(profile.date_hired)}
                    />
                    <Field
                        label="Date terminated"
                        value={formatDate(profile.date_terminated)}
                    />
                </dl>
            </CardContent>
        </Card>
    );
}

function GovernmentIdsCard({ profile }: { profile: EmployeeProfile }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="font-serif text-lg">
                    Government IDs
                </CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="TIN" value={profile.tin} mono />
                    <Field label="SSS" value={profile.sss_number} mono />
                    <Field
                        label="PhilHealth"
                        value={profile.philhealth_number}
                        mono
                    />
                    <Field
                        label="Pag-IBIG"
                        value={profile.pagibig_number}
                        mono
                    />
                </dl>
            </CardContent>
        </Card>
    );
}

function BankCard({ profile }: { profile: EmployeeProfile }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="font-serif text-lg">
                    Bank account
                </CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="Bank" value={profile.bank_name} />
                    <Field
                        label="Account number"
                        value={profile.bank_account_number}
                        mono
                    />
                    <Field
                        label="Account name"
                        value={profile.bank_account_name}
                    />
                </dl>
            </CardContent>
        </Card>
    );
}

interface FieldProps {
    label: string;
    value: string | null | undefined;
    mono?: boolean;
    capitalize?: boolean;
}

function Field({ label, value, mono = false, capitalize = false }: FieldProps) {
    const hasValue = value !== null && value !== undefined && value !== '';

    return (
        <div className="space-y-1">
            <dt className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd
                className={cn(
                    'text-sm',
                    mono && 'font-mono text-xs',
                    capitalize && 'capitalize',
                    !hasValue && 'text-muted-foreground',
                )}
            >
                {hasValue ? value : '—'}
            </dd>
        </div>
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

function formatDate(value: string | null): string | null {
    if (value === null || value === '') {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return DATE_FORMATTER.format(date);
}

export type { Props as EmployeesShowProps };
