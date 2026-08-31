import { Head, useForm } from '@inertiajs/react';
import { ChartNoAxesCombined } from 'lucide-react';
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
import { formatMoney, formatMoneyCompact } from '@/lib/format-money';
import { accountingDashboard } from '@/routes/admin/reports';
import type { AccountingDashboardPageProps, DashboardPreset } from '@/types';

/**
 * The school's own money, from the posted ledger.
 *
 * Every figure here comes from posted journal entries — never from invoices.
 * An invoice is an operational record; a posted entry is the school's
 * position, and the two are allowed to differ. A draft invoice is real work
 * and is not yet revenue.
 *
 * Two kinds of figure sit side by side in the tiles, which the labels have to
 * carry: income, expenses and net income are what moved *between the dates*,
 * while cash, receivables and payables are what the school holds *as at* the
 * end of them. Same row, different questions.
 */
export default function AccountingDashboard({
    filters,
    summary,
    monthlySeries,
}: AccountingDashboardPageProps) {
    const form = useForm({
        preset: filters.preset,
        from: filters.from,
        to: filters.to,
    });

    const apply = (preset: DashboardPreset, from?: string, to?: string) => {
        form.transform(() => ({
            preset,
            ...(preset === 'custom' ? { from: from ?? '', to: to ?? '' } : {}),
        }));

        form.get(accountingDashboard().url, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    // Editing a range rather than reading a resolved one.
    const isCustom = form.data.preset === 'custom';

    const hasMovement =
        summary.income_centavos !== 0 || summary.expenses_centavos !== 0;

    return (
        <>
            <Head title="Accounting dashboard" />

            <div className="mx-auto max-w-screen-2xl space-y-6 p-4">
                <PageHeader
                    eyebrow="FINANCIAL REPORTS"
                    title="Accounting dashboard"
                    description="Posted entries only. Drafts and voided documents are not the school's position and are never counted here."
                />

                <ReportRangeFilter
                    // Which preset is SELECTED is a client-side choice, live
                    // the moment it is clicked — Custom has to unlock the
                    // pickers before any round trip, because the round trip is
                    // what it is unlocking them to compose.
                    preset={form.data.preset}
                    // Which dates are SHOWN is a different question. While a
                    // preset is active the server owns them — "this year" is
                    // whatever this school's periods say — and `useForm` seeds
                    // itself once, so reading form state here left the pickers
                    // showing the range the operator came from beside figures
                    // for the one they had just asked for.
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

                {/*
                  Balances first, then the period figures. A reader scanning
                  left to right gets "what we hold" before "what we made",
                  which is the order a governor asks in.
                */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <StatCard
                        label="Cash balance"
                        value={<Money amount={summary.cash_centavos / 100} />}
                        hint="As at the end of the range"
                    />
                    <StatCard
                        label="Receivables"
                        value={
                            <Money
                                amount={summary.receivables_centavos / 100}
                            />
                        }
                        hint="Owed to the school"
                    />
                    <StatCard
                        label="Payables"
                        value={
                            <Money amount={summary.payables_centavos / 100} />
                        }
                        hint="Owed by the school"
                    />
                    <StatCard
                        label="Income"
                        value={<Money amount={summary.income_centavos / 100} />}
                        hint="Earned in the range"
                    />
                    <StatCard
                        label="Expenses"
                        value={
                            <Money amount={summary.expenses_centavos / 100} />
                        }
                        hint="Spent in the range"
                    />
                    <StatCard
                        label="Net income"
                        value={
                            <Money
                                amount={summary.net_income_centavos / 100}
                                signed
                            />
                        }
                        hint="Income less expenses"
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Income and expenses</CardTitle>
                        <CardDescription>
                            What the school earned and spent, month by month.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {hasMovement ? (
                            <ChartContainer height={320}>
                                <BarChart data={monthlySeries}>
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
                                                                'income_centavos'
                                                                    ? 'Income'
                                                                    : 'Expenses',
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
                                        dataKey="income_centavos"
                                        fill={CHART_COLORS[0]}
                                        radius={[3, 3, 0, 0]}
                                    />
                                    <Bar
                                        dataKey="expenses_centavos"
                                        fill={CHART_COLORS[1]}
                                        radius={[3, 3, 0, 0]}
                                    />
                                </BarChart>
                            </ChartContainer>
                        ) : (
                            <EmptyState
                                icon={ChartNoAxesCombined}
                                title="Nothing posted in this range"
                                description="Approve a document, or widen the dates. Drafts are not counted."
                            />
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Revenue by account</CardTitle>
                        <CardDescription>
                            Grouped by the income accounts this school
                            configured, largest first.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {summary.revenue_by_account.length > 0 ? (
                            <ChartContainer
                                height={Math.max(
                                    200,
                                    summary.revenue_by_account.length * 44,
                                )}
                            >
                                <BarChart
                                    data={summary.revenue_by_account}
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
                                        dataKey="name"
                                        tickLine={false}
                                        axisLine={false}
                                        width={180}
                                    />
                                    <Tooltip
                                        cursor={{ fillOpacity: 0.08 }}
                                        content={({ active, payload }) =>
                                            active && payload?.length ? (
                                                <ChartTooltip
                                                    title={String(
                                                        payload[0]?.payload
                                                            ?.name ?? '',
                                                    )}
                                                    rows={[
                                                        {
                                                            label: 'Earned',
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
                                        {summary.revenue_by_account.map(
                                            (row, index) => (
                                                <Cell
                                                    key={row.account_id}
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
                                icon={ChartNoAxesCombined}
                                title="No revenue in this range"
                                description="Income appears here once an invoice is approved and posted."
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AccountingDashboard.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Reports', href: '#' },
        { title: 'Dashboard', href: accountingDashboard().url },
    ],
};
