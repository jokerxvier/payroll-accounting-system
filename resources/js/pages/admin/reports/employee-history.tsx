import { Head, router } from '@inertiajs/react';
import { Check, ChevronsUpDown, Users } from 'lucide-react';
import { useState } from 'react';
import { ReportExportMenu } from '@/components/admin/report-export-menu';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

interface EmployeeOption {
    lms_staff_id: number;
    full_name: string | null;
}

interface EmployeeIdentity {
    lms_staff_id: number;
    full_name: string | null;
    staff_no: string | null;
    email: string | null;
}

interface HistoryRow {
    payslip_id: number;
    run_id: number;
    pay_period_code: string | null;
    pay_period_start: string | null;
    pay_period_end: string | null;
    computed_at: string | null;
    gross_pay_centavos: number;
    total_employee_deductions_centavos: number;
    net_pay_centavos: number;
    cumulative_gross_centavos: number;
    cumulative_deductions_centavos: number;
    cumulative_net_centavos: number;
}

interface HistoryTotals {
    payslip_count: number;
    gross_pay_centavos: number;
    total_employee_deductions_centavos: number;
    total_net_pay_centavos: number;
}

interface YtdYear {
    year: number;
    payslip_count: number;
    gross_pay_centavos: number;
    total_employee_deductions_centavos: number;
    total_employer_contributions_centavos: number;
    total_net_pay_centavos: number;
}

interface Props {
    filters: { employee: number | null };
    employees: EmployeeOption[];
    employee: EmployeeIdentity | null;
    rows: HistoryRow[];
    totals: HistoryTotals;
    ytd_by_year: YtdYear[];
}

function formatCurrency(centavos: number): string {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(centavos / 100);
}

function formatDate(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function EmployeeHistoryReport({
    filters,
    employees,
    employee,
    rows,
    totals,
    ytd_by_year: ytdByYear,
}: Props) {
    const [pickerOpen, setPickerOpen] = useState(false);

    const exportUrl = filters.employee
        ? `/admin/reports/employee-history/export?employee=${filters.employee}`
        : null;

    const onPick = (value: string) => {
        router.get(
            '/admin/reports/employee-history',
            { employee: value },
            { preserveScroll: true, preserveState: true },
        );
    };

    const triggerLabel = employee
        ? `${employee.full_name ?? `Staff #${employee.lms_staff_id}`} · ${employee.lms_staff_id}`
        : 'Select an employee…';

    return (
        <>
            <Head title="Employee history report" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="REPORTS"
                    title="Employee history"
                    description="Per-employee timeline of payslips with cumulative totals across non-voided runs."
                    actions={
                        exportUrl ? (
                            <ReportExportMenu baseUrl={exportUrl} />
                        ) : undefined
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-medium">
                            Employee
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="space-y-1">
                            <Label htmlFor="employee-pick">Pick employee</Label>
                            <Popover
                                open={pickerOpen}
                                onOpenChange={setPickerOpen}
                            >
                                <PopoverTrigger asChild>
                                    <Button
                                        id="employee-pick"
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={pickerOpen}
                                        className="w-full max-w-md justify-between font-normal"
                                    >
                                        <span
                                            className={cn(
                                                'truncate',
                                                !employee &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {triggerLabel}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent
                                    className="w-(--radix-popover-trigger-width) p-0"
                                    align="start"
                                >
                                    <Command>
                                        <CommandInput placeholder="Search by name or staff id…" />
                                        <CommandList>
                                            <CommandEmpty>
                                                No employee found.
                                            </CommandEmpty>
                                            <CommandGroup>
                                                {employees.map((e) => (
                                                    <CommandItem
                                                        key={e.lms_staff_id}
                                                        // cmdk filters on this string —
                                                        // include both name and id so
                                                        // either matches the search.
                                                        value={`${e.full_name ?? ''} ${e.lms_staff_id}`}
                                                        onSelect={() => {
                                                            setPickerOpen(
                                                                false,
                                                            );
                                                            onPick(
                                                                String(
                                                                    e.lms_staff_id,
                                                                ),
                                                            );
                                                        }}
                                                    >
                                                        <Check
                                                            className={cn(
                                                                'mr-2 h-4 w-4',
                                                                filters.employee ===
                                                                    e.lms_staff_id
                                                                    ? 'opacity-100'
                                                                    : 'opacity-0',
                                                            )}
                                                        />
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {e.lms_staff_id}
                                                        </span>
                                                        <span className="ml-2">
                                                            {e.full_name ??
                                                                'Unknown staff'}
                                                        </span>
                                                    </CommandItem>
                                                ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                        </div>

                        {employee ? (
                            <div className="rounded-md border bg-muted/20 p-3 text-xs text-muted-foreground">
                                <span className="font-medium">
                                    {employee.full_name ??
                                        `Staff #${employee.lms_staff_id}`}
                                </span>
                                {employee.staff_no ? (
                                    <span>
                                        {' · '}
                                        <span className="font-mono">
                                            {employee.staff_no}
                                        </span>
                                    </span>
                                ) : null}
                                {employee.email ? (
                                    <span> · {employee.email}</span>
                                ) : null}
                                {' · '}
                                {totals.payslip_count} payslip
                                {totals.payslip_count === 1 ? '' : 's'} · Net
                                lifetime{' '}
                                <span className="tabular-nums">
                                    {formatCurrency(
                                        totals.total_net_pay_centavos,
                                    )}
                                </span>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>

                {filters.employee && ytdByYear.length > 0 ? (
                    <div className="space-y-2">
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Year-to-date
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {ytdByYear.map((y) => (
                                <Card key={y.year}>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="font-serif text-2xl tracking-tight">
                                            {y.year}
                                        </CardTitle>
                                        <p className="text-xs text-muted-foreground">
                                            {y.payslip_count} payslip
                                            {y.payslip_count === 1 ? '' : 's'}
                                        </p>
                                    </CardHeader>
                                    <CardContent className="space-y-1 text-sm">
                                        <YtdRow
                                            label="Gross"
                                            centavos={y.gross_pay_centavos}
                                        />
                                        <YtdRow
                                            label="EE Deductions"
                                            centavos={
                                                y.total_employee_deductions_centavos
                                            }
                                        />
                                        <YtdRow
                                            label="ER Contributions"
                                            centavos={
                                                y.total_employer_contributions_centavos
                                            }
                                        />
                                        <YtdRow
                                            label="Net Pay"
                                            centavos={y.total_net_pay_centavos}
                                            emphasis
                                        />
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </div>
                ) : null}

                {filters.employee ? (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <Users className="h-4 w-4" />
                                Timeline
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {rows.length === 0 ? (
                                <p className="py-8 text-center text-sm text-muted-foreground">
                                    No payslips for this employee.
                                </p>
                            ) : (
                                <div className="overflow-x-auto rounded-md border">
                                    <Table className="text-sm">
                                        <TableHeader>
                                            <TableRow className="bg-muted/40 hover:bg-muted/40">
                                                <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                    Period
                                                </TableHead>
                                                <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                    Computed
                                                </TableHead>
                                                <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                    Gross
                                                </TableHead>
                                                <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                    Deductions
                                                </TableHead>
                                                <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                    Net
                                                </TableHead>
                                                <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                    Cum. Net
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {rows.map((r) => (
                                                <TableRow key={r.payslip_id}>
                                                    <TableCell className="font-mono text-xs">
                                                        {r.pay_period_code ??
                                                            `Run ${r.run_id}`}
                                                    </TableCell>
                                                    <TableCell className="text-xs text-muted-foreground tabular-nums">
                                                        {formatDate(
                                                            r.computed_at,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {formatCurrency(
                                                            r.gross_pay_centavos,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {formatCurrency(
                                                            r.total_employee_deductions_centavos,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {formatCurrency(
                                                            r.net_pay_centavos,
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-right font-medium tabular-nums">
                                                        {formatCurrency(
                                                            r.cumulative_net_centavos,
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ) : null}
            </div>
        </>
    );
}

function YtdRow({
    label,
    centavos,
    emphasis,
}: {
    label: string;
    centavos: number;
    emphasis?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span
                className={
                    emphasis ? 'font-semibold tabular-nums' : 'tabular-nums'
                }
            >
                {formatCurrency(centavos)}
            </span>
        </div>
    );
}

EmployeeHistoryReport.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/admin/reports/payroll-summary' },
        {
            title: 'Employee history',
            href: '/admin/reports/employee-history',
        },
    ],
};
