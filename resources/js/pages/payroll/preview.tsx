import { Head, Link, usePage } from '@inertiajs/react';
import {
    Calculator,
    Calendar,
    ChevronsUpDown,
    Search,
    User as UserIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Money } from '@/components/money';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { usePayrollPreview } from '@/hooks/use-payroll-preview';
import { cn } from '@/lib/utils';
import { show as employeesShow } from '@/routes/employees';
import { show as previewShow } from '@/routes/payroll/preview';
import type {
    AuditLineDTO,
    PayrollComputationResultDTO,
    PeriodDTO,
    PreviewEmployeeOption,
    PreviewFrequency,
    PreviewPageProps,
    PreviewSelection,
} from '@/types';

const MONTHS: ReadonlyArray<{ value: number; label: string }> = [
    { value: 1, label: 'January' },
    { value: 2, label: 'February' },
    { value: 3, label: 'March' },
    { value: 4, label: 'April' },
    { value: 5, label: 'May' },
    { value: 6, label: 'June' },
    { value: 7, label: 'July' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'October' },
    { value: 11, label: 'November' },
    { value: 12, label: 'December' },
];

/**
 * Year range surfaced in the year picker. Lower bound matches the seeded
 * statutory rate floor (2020); upper bound gives a year of head-room beyond
 * "this year" for HR to model offers / grade changes that take effect next
 * fiscal year.
 */
const YEAR_RANGE = (() => {
    const thisYear = new Date().getFullYear();
    const years: number[] = [];

    for (let y = 2020; y <= thisYear + 1; y += 1) {
        years.push(y);
    }

    return years.reverse();
})();

const DATE_RANGE_FORMATTER = new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
});

function formatPeriodRange(period: PeriodDTO): string {
    const start = new Date(`${period.start}T00:00:00`);
    const end = new Date(`${period.end}T00:00:00`);

    return `${DATE_RANGE_FORMATTER.format(start)} – ${DATE_RANGE_FORMATTER.format(end)}`;
}

function frequencyLabel(
    frequency: PreviewFrequency,
    options: Record<PreviewFrequency, string>,
): string {
    return options[frequency] ?? frequency;
}

export default function PayrollPreview() {
    const { props } = usePage<PreviewPageProps>();
    const {
        employees,
        frequencyOptions,
        defaultPeriod,
        selection: incomingSelection,
        noProfile,
        currentPeriod,
        priorPeriod,
        computedAt,
    } = props;

    const { isComputing, requestRecompute, cancel } = usePayrollPreview();

    const [selection, setSelection] = useState<PreviewSelection>(() => ({
        lms_staff_id: incomingSelection?.lms_staff_id ?? 0,
        frequency: incomingSelection?.frequency ?? defaultPeriod.frequency,
        year: incomingSelection?.year ?? defaultPeriod.year,
        month: incomingSelection?.month ?? defaultPeriod.month,
    }));

    const handleSelectionChange = (next: PreviewSelection) => {
        setSelection(next);

        if (next.lms_staff_id > 0) {
            requestRecompute(next);
        } else {
            // Selection was cleared mid-debounce; cancel the pending request
            // so it doesn't fire against staff_id = 0.
            cancel();
        }
    };

    const selectedEmployee = useMemo(
        () =>
            employees.find((e) => e.lms_staff_id === selection.lms_staff_id) ??
            null,
        [employees, selection.lms_staff_id],
    );

    const hasSelection = selection.lms_staff_id > 0;
    const hasResult = currentPeriod !== null;

    return (
        <>
            <Head title="Payroll preview" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="PAYROLL"
                    title="Preview"
                    description="Compute a single employee's payslip for any period without persisting."
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Selection</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                            <div className="space-y-2 lg:col-span-2">
                                <Label htmlFor="employee-trigger">
                                    Employee
                                </Label>
                                <EmployeeTypeahead
                                    employees={employees}
                                    selected={selectedEmployee}
                                    onSelect={(employee) =>
                                        handleSelectionChange({
                                            ...selection,
                                            lms_staff_id: employee.lms_staff_id,
                                        })
                                    }
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="frequency-trigger">
                                    Frequency
                                </Label>
                                <Select
                                    value={selection.frequency}
                                    onValueChange={(value) =>
                                        handleSelectionChange({
                                            ...selection,
                                            frequency:
                                                value as PreviewFrequency,
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="frequency-trigger"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(
                                            Object.entries(
                                                frequencyOptions,
                                            ) as Array<
                                                [PreviewFrequency, string]
                                            >
                                        ).map(([value, label]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid grid-cols-2 gap-2">
                                <div className="space-y-2">
                                    <Label htmlFor="month-trigger">Month</Label>
                                    <Select
                                        value={String(selection.month)}
                                        onValueChange={(value) =>
                                            handleSelectionChange({
                                                ...selection,
                                                month: Number(value),
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id="month-trigger"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {MONTHS.map((m) => (
                                                <SelectItem
                                                    key={m.value}
                                                    value={String(m.value)}
                                                >
                                                    {m.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="year-trigger">Year</Label>
                                    <Select
                                        value={String(selection.year)}
                                        onValueChange={(value) =>
                                            handleSelectionChange({
                                                ...selection,
                                                year: Number(value),
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id="year-trigger"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {YEAR_RANGE.map((y) => (
                                                <SelectItem
                                                    key={y}
                                                    value={String(y)}
                                                >
                                                    {y}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {!hasSelection && <PreviewEmptyState />}

                {hasSelection && noProfile === true && selectedEmployee && (
                    <NoProfileNotice employee={selectedEmployee} />
                )}

                {hasSelection && noProfile !== true && hasResult && (
                    <div className="grid gap-6 lg:grid-cols-2">
                        <BreakdownColumn
                            heading="Current period"
                            result={currentPeriod}
                            frequencyOptions={frequencyOptions}
                            isComputing={isComputing}
                            isPrior={false}
                        />
                        {priorPeriod ? (
                            <BreakdownColumn
                                heading="Prior period"
                                result={priorPeriod}
                                frequencyOptions={frequencyOptions}
                                isComputing={isComputing}
                                isPrior
                            />
                        ) : (
                            <Card className="flex items-center justify-center">
                                <CardContent className="py-16 text-center">
                                    <p className="text-sm text-muted-foreground">
                                        No prior period available for
                                        comparison.
                                    </p>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}

                {hasSelection &&
                    noProfile !== true &&
                    !hasResult &&
                    isComputing && (
                        <div className="grid gap-6 lg:grid-cols-2">
                            <BreakdownSkeleton />
                            <BreakdownSkeleton />
                        </div>
                    )}

                {computedAt && hasResult && (
                    <p className="text-xs text-muted-foreground">
                        Computed at{' '}
                        <time dateTime={computedAt} className="tabular-nums">
                            {new Date(computedAt).toLocaleString('en-PH')}
                        </time>
                        . The preview never persists — close the tab and the
                        result is gone.
                    </p>
                )}
            </div>
        </>
    );
}

PayrollPreview.layout = {
    breadcrumbs: [
        { title: 'Payroll', href: '#' },
        { title: 'Preview', href: previewShow().url },
    ],
};

interface EmployeeTypeaheadProps {
    employees: PreviewEmployeeOption[];
    selected: PreviewEmployeeOption | null;
    onSelect: (employee: PreviewEmployeeOption) => void;
}

/**
 * A minimal typeahead built on `Popover` + `Input`. The shadcn Command
 * component isn't installed in this repo (per `components/ui/` listing) so
 * filtering is implemented inline. Stage A capped the option list at 50,
 * which is well within "render-and-filter on every keystroke" territory.
 */
function EmployeeTypeahead({
    employees,
    selected,
    onSelect,
}: EmployeeTypeaheadProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const inputRef = useRef<HTMLInputElement | null>(null);

    const filtered = useMemo(() => {
        const trimmed = query.trim().toLowerCase();

        if (trimmed === '') {
            return employees;
        }

        return employees.filter((e) =>
            e.full_name.toLowerCase().includes(trimmed),
        );
    }, [employees, query]);

    useEffect(() => {
        if (open) {
            // Defer to next tick so the popover content is mounted before
            // we focus the input.
            const id = window.setTimeout(() => {
                inputRef.current?.focus();
            }, 0);

            return () => window.clearTimeout(id);
        }

        return undefined;
    }, [open]);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id="employee-trigger"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-label="Select employee"
                    className="w-full justify-between font-normal"
                >
                    <span className="flex items-center gap-2 truncate">
                        <UserIcon className="h-4 w-4 text-muted-foreground" />
                        {selected ? (
                            <span className="truncate">
                                {selected.full_name}
                            </span>
                        ) : (
                            <span className="text-muted-foreground">
                                Pick an employee
                            </span>
                        )}
                    </span>
                    <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                sideOffset={4}
                className="w-[var(--radix-popover-trigger-width)] p-0"
            >
                <div className="flex items-center gap-2 border-b px-3 py-2">
                    <Search className="h-4 w-4 text-muted-foreground" />
                    <Input
                        ref={inputRef}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search employees…"
                        className="h-8 border-0 px-0 shadow-none focus-visible:ring-0"
                        aria-label="Search employees"
                    />
                </div>
                <div className="max-h-72 overflow-y-auto p-1">
                    {filtered.length === 0 ? (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No employees match &quot;{query}&quot;.
                        </p>
                    ) : (
                        filtered.map((employee) => (
                            <button
                                key={employee.lms_staff_id}
                                type="button"
                                onClick={() => {
                                    onSelect(employee);
                                    setOpen(false);
                                    setQuery('');
                                }}
                                className={cn(
                                    'flex w-full flex-col items-start gap-0.5 rounded-sm px-3 py-2 text-left text-sm hover:bg-accent hover:text-accent-foreground focus:bg-accent focus:outline-none',
                                    selected?.lms_staff_id ===
                                        employee.lms_staff_id && 'bg-accent/50',
                                )}
                            >
                                <span className="font-medium">
                                    {employee.full_name}
                                </span>
                                {employee.employment_type && (
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {employee.employment_type}
                                    </span>
                                )}
                            </button>
                        ))
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}

function PreviewEmptyState() {
    return (
        <Card>
            <CardContent className="py-16 text-center">
                <Calculator
                    className="mx-auto mb-3 h-10 w-10 text-muted-foreground/60"
                    strokeWidth={1.5}
                />
                <h3 className="font-serif text-lg font-medium">
                    Pick an employee and period to preview
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">
                    Selections re-compute automatically. Nothing here is saved.
                </p>
            </CardContent>
        </Card>
    );
}

function NoProfileNotice({ employee }: { employee: PreviewEmployeeOption }) {
    return (
        <Card>
            <CardContent className="py-12 text-center">
                <UserIcon
                    className="mx-auto mb-3 h-10 w-10 text-muted-foreground/60"
                    strokeWidth={1.5}
                />
                <h3 className="font-serif text-lg font-medium">
                    No payroll profile
                </h3>
                <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    {employee.full_name} has no payroll profile. Edit their
                    record to add one before computing payroll.
                </p>
                <div className="mt-4">
                    <Button asChild variant="outline">
                        <Link
                            href={
                                employeesShow({
                                    staffId: employee.lms_staff_id,
                                }).url
                            }
                        >
                            Open employee record
                        </Link>
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

interface BreakdownColumnProps {
    heading: string;
    result: PayrollComputationResultDTO;
    frequencyOptions: Record<PreviewFrequency, string>;
    isComputing: boolean;
    isPrior: boolean;
}

function BreakdownColumn({
    heading,
    result,
    frequencyOptions,
    isComputing,
    isPrior,
}: BreakdownColumnProps) {
    const earnings = result.auditLines.filter((l) => l.bucket === 'earning');
    const employeeDeductions = result.auditLines.filter(
        (l) => l.bucket === 'employee_deduction',
    );
    const employerContributions = result.auditLines.filter(
        (l) => l.bucket === 'employer_contribution',
    );

    const hasUnpaidStub =
        result.unpaidDaysCount === 0 &&
        result.unpaidDaysReduction.centavos === 0;
    const hasAllowanceLines = earnings.some(
        (l) =>
            l.code === 'allowance_taxable' ||
            l.code === 'allowance_non_taxable',
    );

    return (
        <Card className={cn(isPrior && 'opacity-90')}>
            <CardHeader>
                <div className="flex items-start justify-between gap-3">
                    <div className="space-y-1">
                        <p className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                            {heading}
                        </p>
                        <CardTitle className="font-serif text-lg">
                            {formatPeriodRange(result.period)}
                        </CardTitle>
                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Calendar className="h-3 w-3" />
                            {frequencyLabel(
                                result.period.frequency,
                                frequencyOptions,
                            )}{' '}
                            · {result.period.daysInPeriod} days
                        </p>
                    </div>
                </div>
            </CardHeader>
            <CardContent
                className={cn(
                    'relative space-y-5',
                    isComputing && 'pointer-events-none opacity-60',
                )}
            >
                {isComputing && (
                    <div className="absolute inset-0 flex items-center justify-center">
                        <BreakdownComputingOverlay />
                    </div>
                )}

                <BreakdownSection
                    title="Earnings"
                    lines={earnings}
                    total={result.grossPay}
                    totalLabel="Gross pay"
                    extra={
                        hasAllowanceLines ? (
                            <Badge
                                variant="outline"
                                className="text-xs text-muted-foreground"
                            >
                                De-minimis caps pending client confirmation
                            </Badge>
                        ) : null
                    }
                />

                <Separator />

                <BreakdownSection
                    title="Employee deductions"
                    lines={employeeDeductions}
                    total={result.totalEmployeeDeductions}
                    totalLabel="Total deductions"
                    extra={
                        hasUnpaidStub ? (
                            <Badge
                                variant="outline"
                                className="text-xs text-muted-foreground"
                            >
                                Unpaid leave: stub — pending LMS leave bridge
                            </Badge>
                        ) : null
                    }
                />

                <Separator />

                <BreakdownSection
                    title="Employer contributions"
                    lines={employerContributions}
                    total={result.totalEmployerContributions}
                    totalLabel="Total employer cost"
                    muted
                    description="Paid alongside payroll. Does not affect employee net pay."
                />

                <Separator />

                <div className="flex items-baseline justify-between rounded-md bg-success/10 px-4 py-3">
                    <span className="font-mono text-xs tracking-widest text-success uppercase">
                        Net pay
                    </span>
                    <span className="font-serif text-2xl font-semibold text-success tabular-nums">
                        <Money amount={result.netPay.centavos / 100} />
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}

interface BreakdownSectionProps {
    title: string;
    lines: AuditLineDTO[];
    total: { centavos: number };
    totalLabel: string;
    description?: string;
    extra?: React.ReactNode;
    muted?: boolean;
}

function BreakdownSection({
    title,
    lines,
    total,
    totalLabel,
    description,
    extra,
    muted = false,
}: BreakdownSectionProps) {
    return (
        <section className="space-y-2">
            <header className="flex items-baseline justify-between gap-3">
                <div>
                    <h4
                        className={cn(
                            'text-sm font-semibold',
                            muted && 'text-muted-foreground',
                        )}
                    >
                        {title}
                    </h4>
                    {description && (
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {extra}
            </header>
            {lines.length === 0 ? (
                <p className="text-sm text-muted-foreground">No items.</p>
            ) : (
                <ul className="divide-y rounded-md border">
                    {lines.map((line, idx) => (
                        <li
                            key={`${line.code}-${idx}`}
                            className={cn(
                                'flex items-center justify-between gap-4 px-3 py-2 text-sm',
                                muted && 'text-muted-foreground',
                            )}
                        >
                            <span className="truncate">{line.label}</span>
                            <span className="tabular-nums">
                                <Money
                                    amount={line.amount.centavos / 100}
                                    showSymbol={false}
                                />
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex items-center justify-between gap-4 px-3 pt-1 text-sm font-medium">
                <span className={cn(muted && 'text-muted-foreground')}>
                    {totalLabel}
                </span>
                <span
                    className={cn(
                        'tabular-nums',
                        muted && 'text-muted-foreground',
                    )}
                >
                    <Money amount={total.centavos / 100} />
                </span>
            </div>
        </section>
    );
}

function BreakdownComputingOverlay() {
    return (
        <div className="rounded-md border bg-card/95 px-4 py-2 shadow-sm">
            <p className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                <span className="relative flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary/60 opacity-75" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-primary" />
                </span>
                Recomputing…
            </p>
        </div>
    );
}

function BreakdownSkeleton() {
    return (
        <Card>
            <CardHeader>
                <Skeleton className="h-4 w-24" />
                <Skeleton className="mt-2 h-6 w-48" />
                <Skeleton className="mt-1 h-3 w-32" />
            </CardHeader>
            <CardContent className="space-y-4">
                <Skeleton className="h-20 w-full" />
                <Skeleton className="h-20 w-full" />
                <Skeleton className="h-12 w-full" />
            </CardContent>
        </Card>
    );
}
