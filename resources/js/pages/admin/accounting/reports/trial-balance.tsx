import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, TriangleAlert } from 'lucide-react';
import { ReportExportMenu } from '@/components/admin/report-export-menu';
import { Money } from '@/components/money';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { trialBalance as trialBalanceRoute } from '@/routes/admin/reports';
import type {
    TrialBalanceRow,
    TrialBalanceTotals,
} from '@/types/ledger-report';

interface Props {
    filters: { from: string; to: string; include_empty: boolean };
    rows: TrialBalanceRow[];
    totals: TrialBalanceTotals;
}

/**
 * A zero prints as an empty cell, not as ₱0.00. A trial balance showing a
 * zero on both sides of one account reads as two offsetting facts rather
 * than one absent one.
 */
function Amount({ centavos }: { centavos: number }) {
    if (centavos === 0) {
        return <span className="text-muted-foreground">&mdash;</span>;
    }

    return <Money amount={centavos / 100} showSymbol={false} />;
}

export default function TrialBalanceReport({ filters, rows, totals }: Props) {
    const form = useForm({
        from: filters.from,
        to: filters.to,
        include_empty: filters.include_empty,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.get(trialBalanceRoute().url, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const exportUrl = `${trialBalanceRoute().url}/export?from=${form.data.from}&to=${form.data.to}&include_empty=${form.data.include_empty ? 1 : 0}`;

    return (
        <>
            <Head title="Trial balance" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="FINANCIAL REPORTS"
                    title="Trial balance"
                    description="Every account's opening balance, movement across the range, and closing balance — taken from posted journal entries only."
                    actions={<ReportExportMenu baseUrl={exportUrl} />}
                />

                {/* The verdict sits above the table because it is the report's
                    conclusion. Leaving the reader to add up six columns to
                    find out whether the books balance defeats the point. */}
                <Card
                    className={
                        totals.is_balanced
                            ? 'border-success/40 bg-success/5'
                            : 'border-destructive/50 bg-destructive/5'
                    }
                >
                    <CardContent className="flex items-start gap-3 py-4">
                        {totals.is_balanced ? (
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
                                {totals.is_balanced
                                    ? 'The ledger balances.'
                                    : 'The ledger does not balance.'}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {totals.is_balanced ? (
                                    <>
                                        Debits equal credits in all three column
                                        pairs, at{' '}
                                        <Money
                                            amount={
                                                totals.closing_debit_centavos /
                                                100
                                            }
                                        />{' '}
                                        closing.
                                    </>
                                ) : (
                                    <>
                                        Closing{' '}
                                        {totals.closing_variance_centavos > 0
                                            ? 'debits exceed credits'
                                            : 'credits exceed debits'}{' '}
                                        by{' '}
                                        <Money
                                            amount={
                                                Math.abs(
                                                    totals.closing_variance_centavos,
                                                ) / 100
                                            }
                                        />
                                        . Every posted entry balances on its
                                        own, so a line reached the ledger
                                        without going through posting.
                                    </>
                                )}
                            </p>
                        </div>
                    </CardContent>
                </Card>

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
                            <div className="flex items-center gap-2 pb-2">
                                <Checkbox
                                    id="include_empty"
                                    checked={form.data.include_empty}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'include_empty',
                                            checked === true,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor="include_empty"
                                    className="font-normal"
                                >
                                    Show accounts with no activity
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

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-sm font-medium">
                            Accounts
                        </CardTitle>
                        <p className="text-xs text-muted-foreground">
                            {rows.length} account{rows.length === 1 ? '' : 's'}
                        </p>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-md border">
                            <Table className="text-sm">
                                <TableHeader>
                                    <TableRow className="bg-muted/40 hover:bg-muted/40">
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Code
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Account
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Opening Dr
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Opening Cr
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Period Dr
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Period Cr
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Closing Dr
                                        </TableHead>
                                        <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                            Closing Cr
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={8}
                                                className="py-8 text-center text-sm text-muted-foreground"
                                            >
                                                No account carried a balance or
                                                moved inside this range.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        rows.map((row) => (
                                            <TableRow key={row.account_id}>
                                                <TableCell className="font-mono text-xs">
                                                    {row.code}
                                                </TableCell>
                                                <TableCell>
                                                    {row.name}
                                                    <span className="ml-2 text-xs text-muted-foreground capitalize">
                                                        {row.type}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Amount
                                                        centavos={
                                                            row.opening_debit_centavos
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Amount
                                                        centavos={
                                                            row.opening_credit_centavos
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Amount
                                                        centavos={
                                                            row.period_debit_centavos
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Amount
                                                        centavos={
                                                            row.period_credit_centavos
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Amount
                                                        centavos={
                                                            row.closing_debit_centavos
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Amount
                                                        centavos={
                                                            row.closing_credit_centavos
                                                        }
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                                {rows.length > 0 && (
                                    <TableFooter>
                                        <TableRow>
                                            <TableCell
                                                colSpan={2}
                                                className="font-medium"
                                            >
                                                Total
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        totals.opening_debit_centavos /
                                                        100
                                                    }
                                                    showSymbol={false}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        totals.opening_credit_centavos /
                                                        100
                                                    }
                                                    showSymbol={false}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        totals.period_debit_centavos /
                                                        100
                                                    }
                                                    showSymbol={false}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        totals.period_credit_centavos /
                                                        100
                                                    }
                                                    showSymbol={false}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        totals.closing_debit_centavos /
                                                        100
                                                    }
                                                    showSymbol={false}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    amount={
                                                        totals.closing_credit_centavos /
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

TrialBalanceReport.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/chart-of-accounts' },
        { title: 'Trial balance', href: '/admin/reports/trial-balance' },
    ],
};
