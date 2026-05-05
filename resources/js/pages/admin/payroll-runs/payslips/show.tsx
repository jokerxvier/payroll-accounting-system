import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download, Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { show as payrollRunsShow } from '@/routes/admin/payroll-runs';
import { pdf as payslipPdf } from '@/routes/admin/payroll-runs/payslips';
import type { PayrollRunSummary, PayslipAuditLine } from '@/types/payroll-run';

interface PayslipDetail {
    id: number;
    lms_staff_id: number;
    gross_pay_centavos: number;
    total_employee_deductions_centavos: number;
    total_employer_contributions_centavos: number;
    net_pay_centavos: number;
    taxable_income_centavos: number;
    audit_lines: PayslipAuditLine[];
    applied_exemptions: string[];
    computed_at: string | null;
}

interface EmployeeIdentity {
    lms_staff_id: number;
    staff_no: string | null;
    full_name: string | null;
    email: string | null;
    tin: string | null;
    sss_number: string | null;
    philhealth_number: string | null;
    pagibig_number: string | null;
}

interface Props {
    run: PayrollRunSummary;
    payslip: PayslipDetail;
    employee: EmployeeIdentity;
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
    if (iso === null) return '—';
    return new Date(iso).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

const BUCKET_TITLES = {
    earning: 'Earnings',
    employee_deduction: 'Employee deductions',
    employer_contribution: 'Employer contributions',
} as const;

export default function PayslipShow({ run, payslip, employee }: Props) {
    const earnings = payslip.audit_lines.filter((l) => l.bucket === 'earning');
    const deductions = payslip.audit_lines.filter(
        (l) => l.bucket === 'employee_deduction',
    );
    const employerLines = payslip.audit_lines.filter(
        (l) => l.bucket === 'employer_contribution',
    );

    return (
        <>
            <Head
                title={`Payslip · ${employee.full_name ?? `Staff ${employee.lms_staff_id}`}`}
            />

            <div className="mx-auto max-w-3xl p-6">
                {/* Toolbar (no-print) */}
                <div className="no-print mb-6 flex items-center justify-between">
                    <Button asChild variant="outline" size="sm">
                        <Link href={payrollRunsShow(run.id).url}>
                            <ArrowLeft className="mr-1 h-4 w-4" />
                            Back to run
                        </Link>
                    </Button>
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <a
                                href={
                                    payslipPdf({
                                        payrollRun: run.id,
                                        payslip: payslip.id,
                                    }).url
                                }
                            >
                                <Download className="mr-1 h-4 w-4" />
                                Download PDF
                            </a>
                        </Button>
                        <Button
                            size="sm"
                            onClick={() => window.print()}
                            aria-label="Print payslip"
                        >
                            <Printer className="mr-1 h-4 w-4" />
                            Print
                        </Button>
                    </div>
                </div>

                {/* Payslip document */}
                <article className="rounded-md border bg-card p-8 shadow-sm print:rounded-none print:border-0 print:p-0 print:shadow-none">
                    {/* Header — THEME.md §6.4 */}
                    <header className="border-b pb-6">
                        <p className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                            Payslip · {run.pay_period?.code ?? `Run ${run.id}`}{' '}
                            ·{' '}
                            {run.pay_period
                                ? `${run.pay_period.start_date} → ${run.pay_period.end_date}`
                                : ''}
                        </p>
                        <h1 className="mt-2 font-serif text-3xl tracking-tight">
                            {employee.full_name ??
                                `Staff #${employee.lms_staff_id}`}
                        </h1>
                        <div className="mt-2 grid gap-1 text-sm text-muted-foreground sm:grid-cols-2">
                            {employee.staff_no ? (
                                <p>
                                    <span className="font-medium">
                                        Staff no.
                                    </span>{' '}
                                    <span className="font-mono">
                                        {employee.staff_no}
                                    </span>
                                </p>
                            ) : null}
                            {employee.email ? (
                                <p>
                                    <span className="font-medium">Email</span>{' '}
                                    {employee.email}
                                </p>
                            ) : null}
                            <p>
                                <span className="font-medium">Computed</span>{' '}
                                {formatDate(payslip.computed_at)}
                            </p>
                            <p>
                                <span className="font-medium">Run status</span>{' '}
                                <span className="capitalize">
                                    {run.status.replace('_', ' ')}
                                </span>
                            </p>
                        </div>
                    </header>

                    {/* Government IDs */}
                    {employee.tin ||
                    employee.sss_number ||
                    employee.philhealth_number ||
                    employee.pagibig_number ? (
                        <section className="border-b py-4">
                            <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                Government IDs
                            </h2>
                            <dl className="mt-2 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                                {employee.tin ? (
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            TIN
                                        </dt>
                                        <dd className="font-mono">
                                            {employee.tin}
                                        </dd>
                                    </div>
                                ) : null}
                                {employee.sss_number ? (
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            SSS
                                        </dt>
                                        <dd className="font-mono">
                                            {employee.sss_number}
                                        </dd>
                                    </div>
                                ) : null}
                                {employee.philhealth_number ? (
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            PhilHealth
                                        </dt>
                                        <dd className="font-mono">
                                            {employee.philhealth_number}
                                        </dd>
                                    </div>
                                ) : null}
                                {employee.pagibig_number ? (
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Pag-IBIG
                                        </dt>
                                        <dd className="font-mono">
                                            {employee.pagibig_number}
                                        </dd>
                                    </div>
                                ) : null}
                            </dl>
                        </section>
                    ) : null}

                    {/* Sections */}
                    <Section
                        title={BUCKET_TITLES.earning}
                        lines={earnings}
                        total={payslip.gross_pay_centavos}
                        totalLabel="Gross pay"
                    />
                    <Section
                        title={BUCKET_TITLES.employee_deduction}
                        lines={deductions}
                        total={payslip.total_employee_deductions_centavos}
                        totalLabel="Total deductions"
                    />

                    {/* Net pay highlight */}
                    <section className="border-y-2 border-foreground py-4">
                        <div className="flex items-baseline justify-between">
                            <p className="text-sm font-semibold tracking-wide uppercase">
                                Net pay
                            </p>
                            <p className="font-serif text-2xl tabular-nums">
                                {formatCurrency(payslip.net_pay_centavos)}
                            </p>
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Taxable income:{' '}
                            <span className="tabular-nums">
                                {formatCurrency(
                                    payslip.taxable_income_centavos,
                                )}
                            </span>
                        </p>
                    </section>

                    {/* Employer contributions — informational, not part of net */}
                    {employerLines.length > 0 ? (
                        <Section
                            title={BUCKET_TITLES.employer_contribution}
                            lines={employerLines}
                            total={
                                payslip.total_employer_contributions_centavos
                            }
                            totalLabel="Total employer contributions"
                            footnote="Paid by the employer; does not affect employee net pay."
                        />
                    ) : null}

                    {/* Exemption footer */}
                    {payslip.applied_exemptions.length > 0 ? (
                        <section className="pt-4">
                            <p className="text-xs text-muted-foreground">
                                <span className="font-medium">
                                    Statutory exemptions applied:
                                </span>{' '}
                                {payslip.applied_exemptions.map((c) => (
                                    <span
                                        key={c}
                                        className="mr-1 inline-block font-mono"
                                    >
                                        {c}
                                    </span>
                                ))}
                            </p>
                        </section>
                    ) : null}

                    {/* Reference footer */}
                    <footer className="mt-8 border-t pt-3 text-center text-[10px] tracking-widest text-muted-foreground uppercase">
                        Run #{run.id} · Payslip #{payslip.id} · Computed{' '}
                        {formatDate(payslip.computed_at)}
                    </footer>
                </article>
            </div>
        </>
    );
}

function Section({
    title,
    lines,
    total,
    totalLabel,
    footnote,
}: {
    title: string;
    lines: PayslipAuditLine[];
    total: number;
    totalLabel: string;
    footnote?: string;
}) {
    return (
        <section className="border-b py-4">
            <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h2>
            {lines.length === 0 ? (
                <p className="mt-2 text-sm text-muted-foreground">No lines.</p>
            ) : (
                <table className="mt-2 w-full text-sm">
                    <tbody>
                        {lines.map((line) => (
                            <tr
                                key={`${line.code}-${line.label}`}
                                className="align-baseline"
                            >
                                <td className="py-1">
                                    <p>{line.label}</p>
                                    <p className="font-mono text-[10px] text-muted-foreground">
                                        {line.code}
                                    </p>
                                </td>
                                <td className="py-1 pl-6 text-right tabular-nums">
                                    {formatCurrency(line.amount)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t">
                            <th className="py-2 text-left text-sm font-medium">
                                {totalLabel}
                            </th>
                            <th className="py-2 pl-6 text-right text-sm font-medium tabular-nums">
                                {formatCurrency(total)}
                            </th>
                        </tr>
                    </tfoot>
                </table>
            )}
            {footnote ? (
                <p className="mt-2 text-xs text-muted-foreground">{footnote}</p>
            ) : null}
        </section>
    );
}

PayslipShow.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin/payroll-runs' },
        { title: 'Payroll runs', href: '/admin/payroll-runs' },
        { title: 'Payslip', href: '/admin/payroll-runs' },
    ],
};
