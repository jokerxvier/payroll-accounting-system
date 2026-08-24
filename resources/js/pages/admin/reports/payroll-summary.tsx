import { Head, useForm } from '@inertiajs/react';
import { FileSpreadsheet } from 'lucide-react';
import { ReportExportMenu } from '@/components/admin/report-export-menu';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

interface SummaryRow {
    run_id: number;
    status: string;
    pay_period_code: string | null;
    pay_period_start: string | null;
    pay_period_end: string | null;
    employee_count: number;
    gross_pay_centavos: number;
    total_employee_deductions_centavos: number;
    total_employer_contributions_centavos: number;
    total_net_pay_centavos: number;
}

interface SummaryTotals {
    employee_count: number;
    gross_pay_centavos: number;
    total_employee_deductions_centavos: number;
    total_employer_contributions_centavos: number;
    total_net_pay_centavos: number;
}

interface Props {
    filters: { from: string; to: string };
    rows: SummaryRow[];
    totals: SummaryTotals;
}

function formatCurrency(centavos: number): string {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(centavos / 100);
}

export default function PayrollSummaryReport({ filters, rows, totals }: Props) {
    const form = useForm({ from: filters.from, to: filters.to });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.get('/admin/reports/payroll-summary', {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const exportUrl = `/admin/reports/payroll-summary/export?from=${form.data.from}&to=${form.data.to}`;

    return (
        <>
            <Head title="Payroll summary report" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="REPORTS"
                    title="Payroll summary"
                    description="Per-period aggregates of gross pay, employee deductions, employer contributions, and net pay across every non-voided payroll run inside the date range."
                    actions={<ReportExportMenu baseUrl={exportUrl} />}
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-medium">
                            Filter
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submit}
                            className="flex flex-wrap items-end gap-3"
                        >
                            <div className="space-y-1">
                                <Label htmlFor="from">From</Label>
                                <Input
                                    id="from"
                                    type="date"
                                    value={form.data.from}
                                    onChange={(e) =>
                                        form.setData('from', e.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="to">To</Label>
                                <Input
                                    id="to"
                                    type="date"
                                    value={form.data.to}
                                    onChange={(e) =>
                                        form.setData('to', e.target.value)
                                    }
                                />
                            </div>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={form.processing}
                            >
                                Apply
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-sm font-medium">
                            <FileSpreadsheet className="h-4 w-4" />
                            Runs in range
                        </CardTitle>
                        <p className="text-xs text-muted-foreground">
                            {rows.length} run{rows.length === 1 ? '' : 's'}
                        </p>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-md border">
                            <Table className="text-sm">
                                <TableHeader>
                                    <TableRow className="bg-muted/40 hover:bg-muted/40">
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Period
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Status
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Employees
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Gross
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            EE Deductions
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            ER Contributions
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Net Pay
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={7}
                                                className="py-8 text-center text-sm text-muted-foreground"
                                            >
                                                No payroll runs in this date
                                                range.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        rows.map((row) => (
                                            <TableRow key={row.run_id}>
                                                <TableCell className="font-mono text-xs">
                                                    {row.pay_period_code ??
                                                        `Run ${row.run_id}`}
                                                </TableCell>
                                                <TableCell className="capitalize">
                                                    {row.status.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </TableCell>
                                                <TableCell className="tabular-nums">
                                                    {row.employee_count}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatCurrency(
                                                        row.gross_pay_centavos,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatCurrency(
                                                        row.total_employee_deductions_centavos,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatCurrency(
                                                        row.total_employer_contributions_centavos,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatCurrency(
                                                        row.total_net_pay_centavos,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                                {rows.length > 0 ? (
                                    <TableFooter>
                                        <TableRow>
                                            <TableCell
                                                colSpan={2}
                                                className="font-semibold"
                                            >
                                                Total
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {totals.employee_count}
                                            </TableCell>
                                            <TableCell className="text-right font-semibold tabular-nums">
                                                {formatCurrency(
                                                    totals.gross_pay_centavos,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-semibold tabular-nums">
                                                {formatCurrency(
                                                    totals.total_employee_deductions_centavos,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-semibold tabular-nums">
                                                {formatCurrency(
                                                    totals.total_employer_contributions_centavos,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-semibold tabular-nums">
                                                {formatCurrency(
                                                    totals.total_net_pay_centavos,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    </TableFooter>
                                ) : null}
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

PayrollSummaryReport.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/admin/reports/payroll-summary' },
        {
            title: 'Payroll summary',
            href: '/admin/reports/payroll-summary',
        },
    ],
};
