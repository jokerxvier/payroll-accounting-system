import { Head, useForm } from '@inertiajs/react';
import { ReportExportMenu } from '@/components/admin/report-export-menu';
import { Money } from '@/components/money';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
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
import { journalReport as journalReportRoute } from '@/routes/admin/reports';
import type { JournalReportEntry } from '@/types/ledger-report';

interface Props {
    filters: { from: string; to: string };
    entries: JournalReportEntry[];
    totals: {
        entry_count: number;
        debit_centavos: number;
        credit_centavos: number;
    };
}

function Amount({ centavos }: { centavos: number }) {
    if (centavos === 0) {
        return <span className="text-muted-foreground">&mdash;</span>;
    }

    return <Money amount={centavos / 100} showSymbol={false} />;
}

export default function JournalReport({ filters, entries, totals }: Props) {
    const form = useForm({ from: filters.from, to: filters.to });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.get(journalReportRoute().url, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const exportUrl = `${journalReportRoute().url}/export?from=${form.data.from}&to=${form.data.to}`;

    return (
        <>
            <Head title="Journal report" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="FINANCIAL REPORTS"
                    title="Journal report"
                    description="Every posted entry in the range, in date order, with the lines that make it up. A correction appears as two offsetting entries rather than as an edit."
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
                                    onChange={(event) =>
                                        form.setData('from', event.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="to">To</Label>
                                <Input
                                    id="to"
                                    type="date"
                                    value={form.data.to}
                                    onChange={(event) =>
                                        form.setData('to', event.target.value)
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
                        <CardTitle className="text-sm font-medium">
                            Posted entries
                        </CardTitle>
                        <p className="text-xs text-muted-foreground">
                            {totals.entry_count} entr
                            {totals.entry_count === 1 ? 'y' : 'ies'}
                        </p>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-md border">
                            <Table className="text-sm">
                                <TableHeader>
                                    <TableRow className="bg-muted/40 hover:bg-muted/40">
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Date
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Entry
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Account
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Description
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Debit
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Credit
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {entries.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="py-8 text-center text-sm text-muted-foreground"
                                            >
                                                No entry was posted with a date
                                                inside this range.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        entries.map((entry) =>
                                            entry.lines.map((line, index) => (
                                                <TableRow
                                                    key={line.id}
                                                    // The top border draws the eye to where one
                                                    // transaction ends and the next begins —
                                                    // the grouping is what makes the page
                                                    // readable as a journal rather than a list
                                                    // of amounts.
                                                    className={
                                                        index === 0
                                                            ? 'border-t-2'
                                                            : undefined
                                                    }
                                                >
                                                    <TableCell className="align-top">
                                                        {index === 0
                                                            ? entry.date
                                                            : ''}
                                                    </TableCell>
                                                    <TableCell className="align-top font-mono text-xs">
                                                        {index === 0 && (
                                                            <>
                                                                {
                                                                    entry.entry_number
                                                                }
                                                                {entry.is_reversal && (
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="ml-2"
                                                                    >
                                                                        reversal
                                                                    </Badge>
                                                                )}
                                                            </>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <span className="font-mono text-xs">
                                                            {line.account_code}
                                                        </span>{' '}
                                                        {line.account_name}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {line.description ??
                                                            (index === 0
                                                                ? entry.narration
                                                                : null)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Amount
                                                            centavos={
                                                                line.debit_centavos
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Amount
                                                            centavos={
                                                                line.credit_centavos
                                                            }
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            )),
                                        )
                                    )}
                                </TableBody>
                                {entries.length > 0 && (
                                    <TableFooter>
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="font-medium"
                                            >
                                                Total
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        totals.debit_centavos /
                                                        100
                                                    }
                                                    showSymbol={false}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        totals.credit_centavos /
                                                        100
                                                    }
                                                    showSymbol={false}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    </TableFooter>
                                )}
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

JournalReport.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/chart-of-accounts' },
        { title: 'Journal report', href: '/admin/reports/journal-report' },
    ],
};
