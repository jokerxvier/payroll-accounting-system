import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, Info, TriangleAlert } from 'lucide-react';
import { CutoverNote } from '@/components/admin/cutover-note';
import { PrintButton } from '@/components/admin/print-button';
import { ReportExportMenu } from '@/components/admin/report-export-menu';
import { StatementTable } from '@/components/admin/statement-table';
import { Money } from '@/components/money';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DatePicker } from '@/components/ui/date-picker';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/format-date';
import {
    balanceSheet as balanceSheetRoute,
    generalLedger,
    incomeStatement,
} from '@/routes/admin/reports';
import type {
    BalanceSheetColumn,
    BalanceSheetCompareMode,
    BalanceSheetPageProps,
    StatementLine,
} from '@/types/financial-statement';

const COMPARISONS: Array<{ value: BalanceSheetCompareMode; label: string }> = [
    { value: 'none', label: 'Nothing' },
    { value: 'previous_month_end', label: 'The end of last month' },
    { value: 'previous_year_end', label: 'The end of last fiscal year' },
    { value: 'custom', label: 'A date I choose' },
];

/** Says whether one date balances, and by how much it does not. */
function Verdict({
    column,
    showDate,
}: {
    column: BalanceSheetColumn;
    showDate: boolean;
}) {
    const date = showDate ? ` as at ${formatDate(column.as_at)}` : '';

    return (
        <div className="flex items-start gap-3">
            {column.is_balanced ? (
                <CheckCircle2
                    className="mt-0.5 h-5 w-5 shrink-0 text-success"
                    aria-hidden="true"
                />
            ) : (
                <TriangleAlert
                    className="mt-0.5 h-5 w-5 shrink-0 text-destructive"
                    aria-hidden="true"
                />
            )}
            <div className="space-y-1">
                <p className="text-sm font-medium">
                    {column.is_balanced
                        ? `The statement balances${date}.`
                        : `The statement does not balance${date}.`}
                </p>
                <p className="text-sm text-muted-foreground">
                    {column.is_balanced ? (
                        <>
                            Assets equal liabilities plus equity, at{' '}
                            <Money
                                amount={Math.abs(column.assets_centavos) / 100}
                            />
                            .
                        </>
                    ) : (
                        <>
                            Assets{' '}
                            {column.variance_centavos > 0
                                ? 'exceed'
                                : 'fall short of'}{' '}
                            liabilities plus equity by{' '}
                            <Money
                                amount={
                                    Math.abs(column.variance_centavos) / 100
                                }
                            />
                            . Every posted entry balances on its own, so a line
                            reached the ledger without going through posting.
                        </>
                    )}
                </p>
            </div>
        </div>
    );
}

export default function BalanceSheetReport({
    filters,
    columns,
    hasVariance,
    notices,
    lines,
    booksOpenedOn,
}: BalanceSheetPageProps) {
    const form = useForm({
        as_at: filters.as_at,
        compare: filters.compare,
        compare_as_at: filters.compare_as_at ?? '',
        include_zero: filters.include_zero,
    });

    // The filters that produced the page, as a query. The export, and the
    // way back from a drill-down, both have to mean the statement on screen
    // rather than one the reader has half chosen.
    const applied = {
        as_at: filters.as_at,
        compare: filters.compare,
        ...(filters.compare === 'custom' && filters.compare_as_at
            ? { compare_as_at: filters.compare_as_at }
            : {}),
        include_zero: filters.include_zero ? '1' : '0',
    };

    const here = balanceSheetRoute({ query: applied }).url;
    const exportUrl = `${balanceSheetRoute().url}/export?${new URLSearchParams(applied).toString()}`;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            as_at: data.as_at,
            compare: data.compare,
            ...(data.compare === 'custom'
                ? { compare_as_at: data.compare_as_at }
                : {}),
            include_zero: data.include_zero ? 1 : 0,
        }));

        form.get(balanceSheetRoute().url, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    /**
     * Where a figure can be read in full.
     *
     * An account opens its ledger up to the date of the column the figure is
     * in. Current-year earnings opens the Income Statement for that column's
     * own fiscal year, because no account holds it. What Retained Earnings
     * is made of keeps the dates the server named for it.
     */
    const hrefFor = (line: StatementLine, index: number): string | null => {
        const column = columns[index];

        if (line.key === 'current_year_earnings') {
            return incomeStatement({
                query: {
                    preset: 'custom',
                    from: column.fiscal_year_start,
                    to: column.as_at,
                    return_to: here,
                },
            }).url;
        }

        if (line.kind === 'detail' || line.key === 'retained_earnings') {
            if (line.drill === null || index > 0) {
                return null;
            }

            return line.drill.report === 'income-statement'
                ? incomeStatement({
                      query: {
                          preset: 'custom',
                          from: line.drill.from,
                          to: line.drill.to,
                          return_to: here,
                      },
                  }).url
                : generalLedger({
                      query: {
                          account_id: line.account_id,
                          from: line.drill.from,
                          to: line.drill.to,
                          return_to: here,
                      },
                  }).url;
        }

        return line.kind === 'account' && line.account_id !== null
            ? generalLedger({
                  query: {
                      account_id: line.account_id,
                      from: column.fiscal_year_start,
                      to: column.as_at,
                      return_to: here,
                  },
              }).url
            : null;
    };

    const primary = columns[0];
    const everyDateBalances = columns.every((column) => column.is_balanced);

    return (
        <>
            <Head title="Balance sheet" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="FINANCIAL REPORTS"
                    title="Balance sheet"
                    description="What the school holds and owes as at one date, from posted journal entries only. Choose an account to see the entries behind its balance."
                    actions={
                        <div className="no-print flex gap-2">
                            <PrintButton />
                            <ReportExportMenu baseUrl={exportUrl} />
                        </div>
                    }
                />

                <CutoverNote
                    booksOpenedOn={booksOpenedOn}
                    from={primary.fiscal_year_start}
                    to={primary.as_at}
                />

                {/* The verdict sits above the statement because it is the
                    statement's conclusion, as on the trial balance. One per
                    date: two dates are two statements. */}
                <Card
                    className={
                        everyDateBalances
                            ? 'border-success/40 bg-success/5'
                            : 'border-destructive/50 bg-destructive/5'
                    }
                >
                    <CardContent className="space-y-3 py-4">
                        {columns.map((column) => (
                            <Verdict
                                key={column.as_at}
                                column={column}
                                showDate={columns.length > 1}
                            />
                        ))}
                    </CardContent>
                </Card>

                <Card className="no-print">
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
                            <div className="w-[11rem] space-y-1">
                                <Label htmlFor="as_at">As at</Label>
                                <DatePicker
                                    id="as_at"
                                    value={form.data.as_at}
                                    onChange={(value) =>
                                        form.setData('as_at', value)
                                    }
                                    placeholder="As at"
                                />
                            </div>
                            <div className="w-[15rem] space-y-1">
                                <Label htmlFor="compare">Compare with</Label>
                                <Select
                                    value={form.data.compare}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'compare',
                                            value as BalanceSheetCompareMode,
                                        )
                                    }
                                >
                                    <SelectTrigger id="compare">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {COMPARISONS.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            {form.data.compare === 'custom' ? (
                                <div className="w-[11rem] space-y-1">
                                    <Label htmlFor="compare_as_at">
                                        Compare as at
                                    </Label>
                                    <DatePicker
                                        id="compare_as_at"
                                        value={form.data.compare_as_at}
                                        onChange={(value) =>
                                            form.setData('compare_as_at', value)
                                        }
                                        placeholder="Earlier date"
                                    />
                                </div>
                            ) : null}
                            <div className="flex items-center gap-2 pb-2">
                                <Checkbox
                                    id="include_zero"
                                    checked={form.data.include_zero}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'include_zero',
                                            checked === true,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor="include_zero"
                                    className="font-normal"
                                >
                                    Show accounts with a nil balance
                                </Label>
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

                {notices.map((notice) => (
                    <p
                        key={notice}
                        role="status"
                        className="flex items-start gap-2 text-sm text-muted-foreground"
                    >
                        <Info
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>{notice}</span>
                    </p>
                ))}

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-sm font-medium">
                            As at {formatDate(primary.as_at)}
                        </CardTitle>
                        <p className="text-xs text-muted-foreground">
                            Current year from{' '}
                            {formatDate(primary.fiscal_year_start)}
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <StatementTable
                            columnLabels={columns.map((column) => column.label)}
                            lines={lines}
                            hasVariance={hasVariance}
                            hrefFor={hrefFor}
                        />
                        <p className="text-xs text-muted-foreground">
                            Figures in brackets are deductions. Earnings are
                            worked out from the income and expense accounts each
                            time this page is opened, because no year-end
                            closing entry moves them into retained earnings.
                            {hasVariance
                                ? ' Each date works out its earnings from its own fiscal year.'
                                : ''}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

BalanceSheetReport.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/chart-of-accounts' },
        { title: 'Balance sheet', href: '/admin/reports/balance-sheet' },
    ],
};
