import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download, Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { show as payrollRunsShow } from '@/routes/admin/payroll-runs';
import { pdf as payslipPdf } from '@/routes/admin/payroll-runs/payslips';
import type { PayrollRunSummary, PayslipAuditLine } from '@/types/payroll-run';

/**
 * The payslip on screen.
 *
 * This and `resources/views/payslips/pdf.blade.php` are one document in two
 * media, so they carry the same structure, the same wording and the same
 * figures. They are not one implementation — dompdf has no flexbox and the
 * browser has no page box — but every difference between them should be a
 * difference the medium forced.
 *
 * The lines, the humanised labels and the contribution ledger all arrive
 * computed from `PayrollRunController::payslipViewModel()`. The page used to
 * split `audit_lines` itself, which is how the two drifted apart.
 */

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

/** One agency's row: what each side paid, and what it was credited. */
interface ContributionRow {
    label: string;
    yours: number;
    school: number;
    credited: number;
}

interface Props {
    run: PayrollRunSummary;
    payslip: PayslipDetail;
    employee: EmployeeIdentity;
    school: {
        name: string | null;
        tin: string | null;
        address: string | null;
        logo_url: string | null;
    };
    earnings: PayslipAuditLine[];
    deductions: PayslipAuditLine[];
    employerLines: PayslipAuditLine[];
    contributions: ContributionRow[];
}

function peso(centavos: number): string {
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
        month: 'long',
        day: 'numeric',
    });
}

/**
 * "1 – 30 July 2026" rather than two ISO strings, matching the PDF.
 *
 * Two details the browser gets wrong on its own: `en-PH` formats a long date
 * month-first ("July 30, 2026"), which would disagree with the PDF's
 * day-first `j F Y`, so the order is pinned with `en-GB`; and a date-only
 * string parses as midnight UTC, which in Manila is the previous evening, so
 * both ends are read at UTC noon where no timezone can move the day.
 */
function periodLabel(start: string, end: string): string {
    const from = new Date(`${start}T12:00:00Z`);
    const to = new Date(`${end}T12:00:00Z`);
    const sameMonth =
        from.getUTCFullYear() === to.getUTCFullYear() &&
        from.getUTCMonth() === to.getUTCMonth();

    const long: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    };

    if (sameMonth) {
        return `${from.getUTCDate()} – ${to.toLocaleDateString('en-GB', long)}`;
    }

    const short: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    };

    return `${from.toLocaleDateString('en-GB', short)} – ${to.toLocaleDateString('en-GB', short)}`;
}

/*
 * The document keeps the printed palette rather than the admin chrome's, and
 * stays on paper-white in dark mode: this panel is a preview of a sheet that
 * will be printed and kept, and a payslip that looks like one thing on screen
 * and another in the hand is the problem this page was fixing.
 */
const INK = 'text-[#141A24]';
const NAVY = 'text-[#1F3A5F]';
const QUIET = 'text-[#5B6675]';
const RULE = 'border-[#C8CFD8]';

export default function PayslipShow({
    run,
    payslip,
    employee,
    school,
    earnings,
    deductions,
    employerLines,
    contributions,
}: Props) {
    const name = employee.full_name ?? `Staff #${employee.lms_staff_id}`;
    const period = run.pay_period;
    const creditedTotal = contributions.reduce((sum, c) => sum + c.credited, 0);

    const governmentIds = [
        { name: 'TIN', value: employee.tin },
        { name: 'SSS', value: employee.sss_number },
        { name: 'PhilHealth', value: employee.philhealth_number },
        { name: 'Pag-IBIG', value: employee.pagibig_number },
    ].filter((id) => id.value);

    return (
        <>
            <Head title={`Payslip · ${name}`} />

            <div className="mx-auto max-w-4xl p-6">
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

                <article
                    className={`rounded-md border bg-white p-8 shadow-sm sm:p-10 print:rounded-none print:border-0 print:p-0 print:shadow-none ${INK}`}
                >
                    {/* Masthead */}
                    <header className="flex items-center gap-4">
                        {school.logo_url ? (
                            <img
                                src={school.logo_url}
                                alt=""
                                className="h-13 w-auto max-w-[140px] object-contain"
                            />
                        ) : null}
                        <div className="min-w-0 flex-1">
                            {school.name ? (
                                <p className="truncate text-lg font-bold">
                                    {school.name}
                                </p>
                            ) : null}
                            <p
                                className={`text-[10px] tracking-[0.16em] uppercase ${QUIET}`}
                            >
                                Statement of earnings
                            </p>
                        </div>
                        <div className="text-right">
                            <p
                                className={`text-[11px] font-bold tracking-[0.24em] uppercase ${NAVY}`}
                            >
                                Payslip
                            </p>
                            <p className={`mt-0.5 text-xs ${QUIET}`}>
                                {period
                                    ? periodLabel(
                                          period.start_date,
                                          period.end_date,
                                      )
                                    : `Run ${run.id}`}
                            </p>
                        </div>
                    </header>

                    <div
                        className={`mt-3 mb-6 border-t-2 border-[#1F3A5F]`}
                        aria-hidden="true"
                    />

                    <div className="grid gap-8 sm:grid-cols-[minmax(0,31fr)_minmax(0,69fr)]">
                        {/* Identity rail */}
                        <div
                            className={`space-y-5 sm:border-r sm:pr-6 ${RULE}`}
                        >
                            <div>
                                <RailLabel>Paid to</RailLabel>
                                <p className="font-serif text-2xl leading-tight">
                                    {name}
                                </p>
                                {employee.staff_no ? (
                                    <p className={`mt-1 text-sm ${QUIET}`}>
                                        Staff no.{' '}
                                        <span className="font-mono">
                                            {employee.staff_no}
                                        </span>
                                    </p>
                                ) : null}
                                {employee.email ? (
                                    <p
                                        className={`text-sm break-words ${QUIET}`}
                                    >
                                        {employee.email}
                                    </p>
                                ) : null}
                            </div>

                            {period ? (
                                <div>
                                    <RailLabel>Period covered</RailLabel>
                                    <p className="text-sm">
                                        {periodLabel(
                                            period.start_date,
                                            period.end_date,
                                        )}
                                    </p>
                                    <p className={`text-sm ${QUIET}`}>
                                        Quote{' '}
                                        <span className="font-mono">
                                            {period.code}
                                        </span>{' '}
                                        to payroll.
                                    </p>
                                </div>
                            ) : null}

                            {governmentIds.length > 0 ? (
                                <div>
                                    <RailLabel>Your numbers</RailLabel>
                                    <dl className="space-y-1 text-sm">
                                        {governmentIds.map((id) => (
                                            <div
                                                key={id.name}
                                                className="flex gap-3"
                                            >
                                                <dt
                                                    className={`w-20 shrink-0 ${QUIET}`}
                                                >
                                                    {id.name}
                                                </dt>
                                                <dd className="font-mono">
                                                    {id.value}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </div>
                            ) : null}

                            {/* Only when it adds something the masthead lacks. */}
                            {school.tin || school.address ? (
                                <div>
                                    <RailLabel>Paid by</RailLabel>
                                    {school.name ? (
                                        <p className="text-sm">{school.name}</p>
                                    ) : null}
                                    {school.address ? (
                                        <p className={`text-sm ${QUIET}`}>
                                            {school.address}
                                        </p>
                                    ) : null}
                                    {school.tin ? (
                                        <p className={`text-sm ${QUIET}`}>
                                            TIN{' '}
                                            <span className="font-mono">
                                                {school.tin}
                                            </span>
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}

                            {payslip.applied_exemptions.length > 0 ? (
                                <div>
                                    <RailLabel>Exemptions applied</RailLabel>
                                    {payslip.applied_exemptions.map((code) => (
                                        <p
                                            key={code}
                                            className="font-mono text-sm"
                                        >
                                            {code}
                                        </p>
                                    ))}
                                </div>
                            ) : null}
                        </div>

                        {/* Money */}
                        <div>
                            <Flow
                                mark="+"
                                title="What you earned"
                                lines={earnings}
                                totalLabel="Gross pay"
                                total={payslip.gross_pay_centavos}
                            />

                            <Flow
                                mark="−"
                                title="What was withheld from your pay"
                                lines={deductions}
                                totalLabel="Total withheld"
                                total={
                                    payslip.total_employee_deductions_centavos
                                }
                            />

                            <div className="mb-5 flex items-center justify-between gap-4 bg-[#1F3A5F] px-4 py-3">
                                <div>
                                    <p className="text-[11px] tracking-[0.2em] text-[#C8CFD8] uppercase">
                                        Net pay
                                    </p>
                                    <p className="mt-0.5 text-xs text-[#9FB0C4]">
                                        Taxable income{' '}
                                        {peso(payslip.taxable_income_centavos)}
                                    </p>
                                </div>
                                <p className="font-serif text-3xl text-white tabular-nums">
                                    {peso(payslip.net_pay_centavos)}
                                </p>
                            </div>

                            {employerLines.length > 0 ? (
                                <Flow
                                    mark="→"
                                    accent
                                    title="Paid for you, on top of your pay"
                                    note="The school pays these in your name. They are not taken from your pay and do not change the figure above."
                                    lines={employerLines}
                                    totalLabel="Total paid by the school"
                                    total={
                                        payslip.total_employer_contributions_centavos
                                    }
                                />
                            ) : null}

                            {contributions.length > 0 ? (
                                <section className={`border p-4 ${RULE}`}>
                                    <h2 className="text-sm font-bold">
                                        Credited to your record this period
                                    </h2>
                                    <p className={`mt-0.5 text-xs ${QUIET}`}>
                                        Your share and the school&rsquo;s share
                                        reach each agency together. This is the
                                        figure they hold against your name.
                                    </p>
                                    <table className="mt-3 w-full text-xs">
                                        <thead>
                                            <tr
                                                className={`border-b ${RULE} ${QUIET}`}
                                            >
                                                <th className="py-1 text-left font-normal tracking-wider uppercase">
                                                    Agency
                                                </th>
                                                <th className="py-1 text-right font-normal tracking-wider uppercase">
                                                    Your share
                                                </th>
                                                <th className="py-1 text-right font-normal tracking-wider uppercase">
                                                    School&rsquo;s share
                                                </th>
                                                <th className="py-1 pl-3 text-right font-normal tracking-wider uppercase">
                                                    Credited
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {contributions.map((row) => (
                                                <tr key={row.label}>
                                                    <td className="py-1">
                                                        {row.label}
                                                    </td>
                                                    <td className="py-1 text-right tabular-nums">
                                                        {peso(row.yours)}
                                                    </td>
                                                    <td className="py-1 text-right tabular-nums">
                                                        {peso(row.school)}
                                                    </td>
                                                    <td
                                                        className={`border-l py-1 pl-3 text-right font-bold tabular-nums ${RULE} text-[#0F5C4A]`}
                                                    >
                                                        {peso(row.credited)}
                                                    </td>
                                                </tr>
                                            ))}
                                            <tr
                                                className={`border-t font-bold ${RULE}`}
                                            >
                                                <td className="py-1">Total</td>
                                                <td />
                                                <td />
                                                <td
                                                    className={`border-l py-1 pl-3 text-right tabular-nums ${RULE} text-[#0F5C4A]`}
                                                >
                                                    {peso(creditedTotal)}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </section>
                            ) : null}
                        </div>
                    </div>

                    <footer
                        className={`mt-8 flex flex-wrap justify-between gap-2 border-t pt-3 text-[11px] ${RULE} ${QUIET}`}
                    >
                        <span>
                            Computed {formatDate(payslip.computed_at)}. Keep
                            this for your records — it is not a demand for
                            payment.
                        </span>
                        <span className="font-mono">
                            Run {run.id} · Payslip {payslip.id}
                        </span>
                    </footer>
                </article>
            </div>
        </>
    );
}

function RailLabel({ children }: { children: React.ReactNode }) {
    return (
        <p className={`mb-1 text-[10px] tracking-[0.14em] uppercase ${QUIET}`}>
            {children}
        </p>
    );
}

/**
 * One money flow. The three are deliberately not rendered identically:
 * presenting them as three matching lists is why staff read employer
 * contributions as money taken from them.
 */
function Flow({
    mark,
    title,
    note,
    lines,
    totalLabel,
    total,
    accent = false,
}: {
    mark: string;
    title: string;
    note?: string;
    lines: PayslipAuditLine[];
    totalLabel: string;
    total: number;
    accent?: boolean;
}) {
    return (
        <section className="mb-5">
            <h2 className="flex items-baseline gap-2 text-sm font-bold">
                <span
                    className={`text-lg ${accent ? 'text-[#0F5C4A]' : NAVY}`}
                    aria-hidden="true"
                >
                    {mark}
                </span>
                {title}
            </h2>
            {note ? (
                <p className={`mt-1 ml-6 text-xs ${QUIET}`}>{note}</p>
            ) : null}

            {lines.length === 0 ? (
                <p className={`mt-1 ml-6 text-xs ${QUIET}`}>
                    Nothing this period.
                </p>
            ) : (
                <table className="mt-2 w-full text-sm">
                    <tbody>
                        {lines.map((line, index) => (
                            <tr
                                key={`${line.code}-${line.label}`}
                                className={
                                    index % 2 === 1 ? 'bg-[#EEF1F5]' : ''
                                }
                            >
                                <td className="py-1.5 pl-6">{line.label}</td>
                                <td className="py-1.5 pr-1 text-right tabular-nums">
                                    {peso(line.amount)}
                                </td>
                            </tr>
                        ))}
                        <tr className={`border-t font-bold ${RULE}`}>
                            <td className="pt-2">{totalLabel}</td>
                            <td className="pt-2 pr-1 text-right tabular-nums">
                                {peso(total)}
                            </td>
                        </tr>
                    </tbody>
                </table>
            )}
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
