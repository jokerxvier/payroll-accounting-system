import { Head, router } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EmployeeEditSheet } from '@/components/employees/edit-sheet';
import { EmptyState } from '@/components/empty-state';
import { InlineMoneyEdit } from '@/components/inline-money-edit';
import { PageHeader } from '@/components/page-header';
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
    EmployeeDetail,
    EmployeeProfile,
    EmploymentTypeOption,
    PayFrequency,
} from '@/types';

interface Props {
    employee: EmployeeDetail;
    employmentTypeOptions: EmploymentTypeOption[];
}

const PAY_FREQUENCY_LABEL: Record<PayFrequency, string> = {
    monthly: 'Monthly',
    semi_monthly: 'Semi-monthly',
};

const DATE_FORMATTER = new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
});

export default function EmployeesShow({
    employee,
    employmentTypeOptions,
}: Props) {
    const [editOpen, setEditOpen] = useState(false);

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

function ProfileSection({
    profile,
    staffId,
}: {
    profile: EmployeeProfile;
    staffId: number;
}) {
    return (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <SalaryCard profile={profile} staffId={staffId} />
            <StatusCard profile={profile} />
            <GovernmentIdsCard profile={profile} />
            <BankCard profile={profile} />
            <DeductionsPlaceholderCard />
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

function DeductionsPlaceholderCard() {
    return (
        <Card className="lg:col-span-2">
            <CardHeader>
                <CardTitle className="font-serif text-lg">
                    Custom deductions
                </CardTitle>
            </CardHeader>
            <CardContent>
                <EmptyState
                    title="No custom deductions yet"
                    description="Configured in Phase 2 (Week 7) — coming after the payroll computation engine ships."
                />
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
