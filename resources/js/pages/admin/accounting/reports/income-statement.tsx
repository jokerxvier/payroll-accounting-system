import { Head, useForm } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { CutoverNote } from '@/components/admin/cutover-note';
import { PrintButton } from '@/components/admin/print-button';
import { ReportExportMenu } from '@/components/admin/report-export-menu';
import { ReportRangeFilter } from '@/components/admin/report-range-filter';
import { StatementTable } from '@/components/admin/statement-table';
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
    generalLedger,
    incomeStatement as incomeStatementRoute,
} from '@/routes/admin/reports';
import type { DashboardPreset } from '@/types';
import type {
    IncomeStatementPageProps,
    StatementCompareMode,
} from '@/types/financial-statement';

const COMPARISONS: Array<{ value: StatementCompareMode; label: string }> = [
    { value: 'none', label: 'Nothing' },
    { value: 'previous_period', label: 'The period before' },
    { value: 'previous_year', label: 'The same period last year' },
    { value: 'monthly', label: 'Month by month' },
    { value: 'custom', label: 'Dates I choose' },
];

export default function IncomeStatementReport({
    filters,
    columns,
    hasVariance,
    notices,
    lines,
    booksOpenedOn,
    backHref,
}: IncomeStatementPageProps) {
    const form = useForm({
        preset: filters.preset,
        from: filters.from,
        to: filters.to,
        compare: filters.compare,
        compare_from: filters.compare_from ?? '',
        compare_to: filters.compare_to ?? '',
        include_zero: filters.include_zero,
    });

    /**
     * Every visit carries every filter. A preset click that dropped the
     * comparison would quietly undo a choice made a moment earlier.
     */
    const apply = (overrides: Partial<typeof form.data> = {}) => {
        const next = { ...form.data, ...overrides };

        form.transform(() => ({
            preset: next.preset,
            ...(next.preset === 'custom'
                ? { from: next.from, to: next.to }
                : {}),
            compare: next.compare,
            ...(next.compare === 'custom'
                ? {
                      compare_from: next.compare_from,
                      compare_to: next.compare_to,
                  }
                : {}),
            include_zero: next.include_zero ? 1 : 0,
            // Carried along, or changing a filter would take away the way
            // back to the report this one was opened from.
            ...(backHref ? { return_to: backHref } : {}),
        }));

        form.get(incomeStatementRoute().url, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const isCustomRange = form.data.preset === 'custom';
    const isCustomComparison = form.data.compare === 'custom';

    // The filters that produced the page, as a query. The export, and the
    // way back from a drill-down, both have to mean the statement on screen.
    const applied = {
        preset: 'custom',
        from: filters.from,
        to: filters.to,
        compare: filters.compare,
        ...(filters.compare === 'custom'
            ? {
                  compare_from: filters.compare_from ?? '',
                  compare_to: filters.compare_to ?? '',
              }
            : {}),
        include_zero: filters.include_zero ? '1' : '0',
    };

    const here = incomeStatementRoute({ query: applied }).url;
    const exportUrl = `${incomeStatementRoute().url}/export?${new URLSearchParams(applied).toString()}`;

    const hasAccounts = lines.some((line) => line.kind === 'account');

    return (
        <>
            <Head title="Income statement" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="FINANCIAL REPORTS"
                    title="Income statement"
                    description="What the school earned and spent between two dates, from posted journal entries only. Choose a figure to see the entries behind it."
                    backHref={backHref ?? undefined}
                    actions={
                        <div className="no-print flex gap-2">
                            <PrintButton />
                            <ReportExportMenu baseUrl={exportUrl} />
                        </div>
                    }
                />

                <CutoverNote
                    booksOpenedOn={booksOpenedOn}
                    from={filters.from}
                    to={filters.to}
                />

                <div className="no-print space-y-6">
                    <ReportRangeFilter
                        // The selected preset is the form's, live on click;
                        // the dates shown are the server's while a preset is
                        // active. See the accounting dashboard for why.
                        preset={form.data.preset}
                        from={isCustomRange ? form.data.from : filters.from}
                        to={isCustomRange ? form.data.to : filters.to}
                        processing={form.processing}
                        onPreset={(preset: DashboardPreset) => {
                            form.setData('preset', preset);

                            if (preset !== 'custom') {
                                apply({ preset });
                            }
                        }}
                        onFrom={(from) => form.setData('from', from)}
                        onTo={(to) => form.setData('to', to)}
                        onApply={() => apply()}
                    />

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">
                                Compare with
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap items-end gap-3">
                            <div className="w-[16rem] space-y-1">
                                <Label htmlFor="compare">Comparison</Label>
                                <Select
                                    value={form.data.compare}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'compare',
                                            value as StatementCompareMode,
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

                            {isCustomComparison ? (
                                <>
                                    <div className="w-[11rem] space-y-1">
                                        <Label htmlFor="compare_from">
                                            Compare from
                                        </Label>
                                        <DatePicker
                                            id="compare_from"
                                            value={form.data.compare_from}
                                            onChange={(value) =>
                                                form.setData(
                                                    'compare_from',
                                                    value,
                                                )
                                            }
                                            placeholder="From"
                                        />
                                    </div>
                                    <div className="w-[11rem] space-y-1">
                                        <Label htmlFor="compare_to">
                                            Compare to
                                        </Label>
                                        <DatePicker
                                            id="compare_to"
                                            value={form.data.compare_to}
                                            onChange={(value) =>
                                                form.setData(
                                                    'compare_to',
                                                    value,
                                                )
                                            }
                                            placeholder="To"
                                        />
                                    </div>
                                </>
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
                                    Show accounts with no activity
                                </Label>
                            </div>

                            <Button
                                type="button"
                                size="sm"
                                disabled={form.processing}
                                onClick={() => apply()}
                            >
                                Apply
                            </Button>
                        </CardContent>
                    </Card>
                </div>

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
                            {formatDate(filters.from)} to{' '}
                            {formatDate(filters.to)}
                        </CardTitle>
                        <p className="text-xs text-muted-foreground">
                            Amounts in Philippine pesos
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <StatementTable
                            columnLabels={columns.map((column) => column.label)}
                            lines={lines}
                            hasVariance={hasVariance}
                            // Each figure opens the ledger for its own
                            // column's dates, so February's salaries open
                            // February's entries.
                            hrefFor={(line, index) =>
                                line.kind === 'account' &&
                                line.account_id !== null
                                    ? generalLedger({
                                          query: {
                                              account_id: line.account_id,
                                              from: columns[index].from,
                                              to: columns[index].to,
                                              return_to: here,
                                          },
                                      }).url
                                    : null
                            }
                        />
                        {hasAccounts ? (
                            <p className="text-xs text-muted-foreground">
                                Figures in brackets are negative.
                                {hasVariance
                                    ? ' A change is shown in green when it raises profit and in red when it lowers it, so a rise in an expense is red.'
                                    : ''}
                            </p>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No income or expense account moved between these
                                dates. Choose a wider range, or check that the
                                entries have been posted.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

IncomeStatementReport.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/chart-of-accounts' },
        { title: 'Income statement', href: '/admin/reports/income-statement' },
    ],
};
