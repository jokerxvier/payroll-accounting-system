import { Head, Link, useForm } from '@inertiajs/react';
import { ReceiptText } from 'lucide-react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { ReportRangeFilter } from '@/components/admin/report-range-filter';
import { EmptyState } from '@/components/empty-state';
import { Money } from '@/components/money';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    CHART_COLORS,
    ChartContainer,
    ChartTooltip,
} from '@/components/ui/chart';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney, formatMoneyCompact } from '@/lib/format-money';
import { index as invoicesIndex } from '@/routes/admin/invoices';
import { invoiceDashboard } from '@/routes/admin/reports';
import type {
    DashboardPreset,
    InvoiceDashboardPageProps,
    OutstandingPayerRow,
} from '@/types';

/**
 * Billing and collections — the operational view.
 *
 * Reads documents and payments, not the ledger, and is allowed to differ from
 * the accounting dashboard. A draft invoice is real work an officer is
 * chasing; it is not yet revenue, and only approving it makes it so.
 *
 * Two kinds of figure again. **Invoiced and Collected are ranged** — billed
 * and received between the dates. **Outstanding and Overdue are as at today** —
 * what is owed right now, whenever it was billed. Ranging Outstanding would
 * answer "how much of this month's billing is unpaid", which is a much smaller
 * number wearing the same label.
 */
export default function InvoiceDashboard({
    filters,
    summary,
}: InvoiceDashboardPageProps) {
    const form = useForm({
        preset: filters.preset,
        from: filters.from,
        to: filters.to,
    });

    const isCustom = form.data.preset === 'custom';

    const apply = (preset: DashboardPreset, from?: string, to?: string) => {
        form.transform(() => ({
            preset,
            ...(preset === 'custom' ? { from: from ?? '', to: to ?? '' } : {}),
        }));

        form.get(invoiceDashboard().url, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const hasAging = summary.aging.some((bucket) => bucket.centavos !== 0);
    const hasBilling = summary.monthly.some(
        (point) =>
            point.invoiced_centavos !== 0 || point.collected_centavos !== 0,
    );

    return (
        <>
            <Head title="Invoice dashboard" />

            <div className="mx-auto max-w-screen-2xl space-y-6 p-4">
                <PageHeader
                    eyebrow="FINANCIAL REPORTS"
                    title="Invoice dashboard"
                    description="Billing and collections. Approved documents only — drafts are work in progress, and voided ones are not owed."
                />

                <ReportRangeFilter
                    preset={form.data.preset}
                    from={isCustom ? form.data.from : filters.from}
                    to={isCustom ? form.data.to : filters.to}
                    processing={form.processing}
                    onPreset={(preset) => {
                        form.setData('preset', preset);

                        if (preset !== 'custom') {
                            apply(preset);
                        }
                    }}
                    onFrom={(from) => form.setData('from', from)}
                    onTo={(to) => form.setData('to', to)}
                    onApply={() =>
                        apply('custom', form.data.from, form.data.to)
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Total invoiced"
                        value={
                            <Money amount={summary.invoiced_centavos / 100} />
                        }
                        hint="Billed in the range"
                    />
                    <StatCard
                        label="Collected"
                        value={
                            <Money amount={summary.collected_centavos / 100} />
                        }
                        hint="Received in the range"
                    />
                    <StatCard
                        label="Outstanding"
                        value={
                            <Money
                                amount={summary.outstanding_centavos / 100}
                            />
                        }
                        // Named as invoices on purpose. The accounting
                        // dashboard's Receivables reads the AR control account,
                        // which also carries opening balances posted at
                        // cutover — real money owed, with no invoice behind it
                        // to chase. The two figures differ by exactly that, and
                        // both are right.
                        hint="Unpaid invoices, whenever billed"
                    />
                    <StatCard
                        label="Overdue"
                        value={
                            <Money amount={summary.overdue_centavos / 100} />
                        }
                        hint={`Past due as at ${summary.as_of}`}
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Invoice status</CardTitle>
                            <CardDescription>
                                Overdue is a cut across unpaid and partly paid,
                                not a fourth kind — it is counted beside them,
                                never inside them.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {summary.statuses.some((s) => s.count > 0) ? (
                                <ChartContainer height={260}>
                                    <BarChart
                                        data={summary.statuses}
                                        layout="vertical"
                                        margin={{ left: 8, right: 24 }}
                                    >
                                        <CartesianGrid
                                            horizontal={false}
                                            strokeDasharray="3 3"
                                        />
                                        <XAxis
                                            type="number"
                                            tickLine={false}
                                            axisLine={false}
                                            tickFormatter={(value: number) =>
                                                formatMoneyCompact(value / 100)
                                            }
                                        />
                                        <YAxis
                                            type="category"
                                            dataKey="label"
                                            tickLine={false}
                                            axisLine={false}
                                            width={110}
                                        />
                                        <Tooltip
                                            cursor={{ fillOpacity: 0.08 }}
                                            content={({ active, payload }) =>
                                                active && payload?.length ? (
                                                    <ChartTooltip
                                                        title={String(
                                                            payload[0]?.payload
                                                                ?.label ?? '',
                                                        )}
                                                        rows={[
                                                            {
                                                                label: 'Documents',
                                                                value: String(
                                                                    payload[0]
                                                                        ?.payload
                                                                        ?.count ??
                                                                        0,
                                                                ),
                                                            },
                                                            {
                                                                label: 'Value',
                                                                value: formatMoney(
                                                                    Number(
                                                                        payload[0]
                                                                            ?.value ??
                                                                            0,
                                                                    ) / 100,
                                                                ),
                                                            },
                                                        ]}
                                                    />
                                                ) : null
                                            }
                                        />
                                        <Bar
                                            dataKey="centavos"
                                            radius={[0, 3, 3, 0]}
                                        >
                                            {summary.statuses.map(
                                                (status, index) => (
                                                    <Cell
                                                        key={status.key}
                                                        fill={
                                                            CHART_COLORS[
                                                                index %
                                                                    CHART_COLORS.length
                                                            ]
                                                        }
                                                    />
                                                ),
                                            )}
                                        </Bar>
                                    </BarChart>
                                </ChartContainer>
                            ) : (
                                <EmptyState
                                    icon={ReceiptText}
                                    title="No approved invoices yet"
                                    description="Approve a draft and it appears here."
                                />
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Receivables ageing</CardTitle>
                            <CardDescription>
                                Unpaid balances by how long they have been owed,
                                as at {summary.as_of}. A part-paid invoice
                                contributes only its remainder.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {hasAging ? (
                                <ChartContainer height={260}>
                                    <BarChart data={summary.aging}>
                                        <CartesianGrid
                                            vertical={false}
                                            strokeDasharray="3 3"
                                        />
                                        <XAxis
                                            dataKey="label"
                                            tickLine={false}
                                            axisLine={false}
                                        />
                                        <YAxis
                                            tickLine={false}
                                            axisLine={false}
                                            width={72}
                                            tickFormatter={(value: number) =>
                                                formatMoneyCompact(value / 100)
                                            }
                                        />
                                        <Tooltip
                                            cursor={{ fillOpacity: 0.08 }}
                                            content={({
                                                active,
                                                payload,
                                                label,
                                            }) =>
                                                active && payload?.length ? (
                                                    <ChartTooltip
                                                        title={String(label)}
                                                        rows={[
                                                            {
                                                                label: 'Owed',
                                                                value: formatMoney(
                                                                    Number(
                                                                        payload[0]
                                                                            ?.value ??
                                                                            0,
                                                                    ) / 100,
                                                                ),
                                                            },
                                                        ]}
                                                    />
                                                ) : null
                                            }
                                        />
                                        <Bar
                                            dataKey="centavos"
                                            radius={[3, 3, 0, 0]}
                                        >
                                            {summary.aging.map(
                                                (bucket, index) => (
                                                    <Cell
                                                        key={bucket.key}
                                                        fill={
                                                            CHART_COLORS[
                                                                index %
                                                                    CHART_COLORS.length
                                                            ]
                                                        }
                                                    />
                                                ),
                                            )}
                                        </Bar>
                                    </BarChart>
                                </ChartContainer>
                            ) : (
                                <EmptyState
                                    icon={ReceiptText}
                                    title="Nothing outstanding"
                                    description="Every approved invoice has been settled."
                                />
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Billed and collected</CardTitle>
                        <CardDescription>
                            Two independent figures, never joined: an invoice
                            raised in August and paid in September is August's
                            billing and September's collection.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {hasBilling ? (
                            <>
                                {/*
                                  Our own key rather than recharts' Legend,
                                  which orders itself by internal registration
                                  and offers no supported way to set that — it
                                  read "Collected, Invoiced" while the bars drew
                                  the other way round.
                                */}
                                <ChartKey
                                    items={[
                                        {
                                            label: 'Invoiced',
                                            color: CHART_COLORS[0],
                                        },
                                        {
                                            label: 'Collected',
                                            color: CHART_COLORS[1],
                                        },
                                    ]}
                                />
                                <ChartContainer height={320}>
                                    <BarChart data={summary.monthly}>
                                        <CartesianGrid
                                            vertical={false}
                                            strokeDasharray="3 3"
                                        />
                                        <XAxis
                                            dataKey="label"
                                            tickLine={false}
                                            axisLine={false}
                                        />
                                        <YAxis
                                            tickLine={false}
                                            axisLine={false}
                                            width={72}
                                            tickFormatter={(value: number) =>
                                                formatMoneyCompact(value / 100)
                                            }
                                        />
                                        <Tooltip
                                            cursor={{ fillOpacity: 0.08 }}
                                            content={({
                                                active,
                                                payload,
                                                label,
                                            }) =>
                                                active && payload?.length ? (
                                                    <ChartTooltip
                                                        title={String(label)}
                                                        rows={payload.map(
                                                            (entry, index) => ({
                                                                label:
                                                                    entry.dataKey ===
                                                                    'invoiced_centavos'
                                                                        ? 'Invoiced'
                                                                        : 'Collected',
                                                                color: CHART_COLORS[
                                                                    index
                                                                ],
                                                                value: formatMoney(
                                                                    Number(
                                                                        entry.value ??
                                                                            0,
                                                                    ) / 100,
                                                                ),
                                                            }),
                                                        )}
                                                    />
                                                ) : null
                                            }
                                        />
                                        <Bar
                                            dataKey="invoiced_centavos"
                                            name="Invoiced"
                                            fill={CHART_COLORS[0]}
                                            radius={[3, 3, 0, 0]}
                                        />
                                        <Bar
                                            dataKey="collected_centavos"
                                            name="Collected"
                                            fill={CHART_COLORS[1]}
                                            radius={[3, 3, 0, 0]}
                                        />
                                    </BarChart>
                                </ChartContainer>
                            </>
                        ) : (
                            <EmptyState
                                icon={ReceiptText}
                                title="Nothing billed or collected in this range"
                                description="Widen the dates, or approve a draft invoice."
                            />
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Who owes the most</CardTitle>
                        <CardDescription>
                            One row per payer, not per child — a family with
                            three at the school owes once. Open a row to see
                            their invoices.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="p-0 sm:p-0">
                        {summary.top_outstanding.length > 0 ? (
                            <div className="overflow-x-auto">
                                <Table className="text-sm">
                                    <TableHeader>
                                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                                            <TableHead>Payer</TableHead>
                                            <TableHead>Student</TableHead>
                                            <TableHead className="text-right">
                                                Invoiced
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Paid
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Outstanding
                                            </TableHead>
                                            <TableHead>Oldest due</TableHead>
                                            <TableHead className="text-right">
                                                Days late
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {summary.top_outstanding.map((row) => (
                                            <PayerRow
                                                key={row.contact_id}
                                                row={row}
                                            />
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        ) : (
                            <div className="p-6">
                                <EmptyState
                                    icon={ReceiptText}
                                    title="Nobody owes anything"
                                    description="Every approved invoice has been settled in full."
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

/** Which colour is which series, in the order the bars are drawn. */
function ChartKey({
    items,
}: {
    items: Array<{ label: string; color: string }>;
}) {
    return (
        <div className="flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
            {items.map((item) => (
                <span key={item.label} className="flex items-center gap-1.5">
                    <span
                        aria-hidden
                        className="h-2 w-2 rounded-[2px]"
                        style={{ backgroundColor: item.color }}
                    />
                    {item.label}
                </span>
            ))}
        </div>
    );
}

function PayerRow({ row }: { row: OutstandingPayerRow }) {
    return (
        <TableRow>
            <TableCell className="font-medium">
                <Link
                    href={
                        invoicesIndex({
                            query: {
                                type: 'sales',
                                contact_id: row.contact_id,
                            },
                        }).url
                    }
                    className="underline-offset-4 hover:underline"
                >
                    {row.contact_name}
                </Link>
            </TableCell>
            <TableCell className="text-muted-foreground">
                {row.students.length > 0 ? row.students.join(', ') : '—'}
            </TableCell>
            <TableCell className="text-right">
                <Money
                    amount={row.invoiced_centavos / 100}
                    showSymbol={false}
                />
            </TableCell>
            <TableCell className="text-right">
                <Money amount={row.paid_centavos / 100} showSymbol={false} />
            </TableCell>
            <TableCell className="text-right font-medium">
                <Money
                    amount={row.outstanding_centavos / 100}
                    showSymbol={false}
                />
            </TableCell>
            <TableCell className="font-mono text-xs text-muted-foreground">
                {row.oldest_due_date ?? '—'}
            </TableCell>
            <TableCell className="text-right tabular-nums">
                {row.days_overdue > 0 ? row.days_overdue : '—'}
            </TableCell>
            <TableCell>
                <PayerStatusBadge status={row.status} />
            </TableCell>
        </TableRow>
    );
}

function PayerStatusBadge({
    status,
}: {
    status: OutstandingPayerRow['status'];
}) {
    if (status === 'overdue') {
        return <Badge variant="destructive">Overdue</Badge>;
    }

    if (status === 'partially_paid') {
        return <Badge variant="outline">Partly paid</Badge>;
    }

    return <Badge variant="secondary">Unpaid</Badge>;
}

InvoiceDashboard.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Reports', href: '#' },
        { title: 'Invoices', href: invoiceDashboard().url },
    ],
};
