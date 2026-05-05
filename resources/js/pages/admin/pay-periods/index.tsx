import { Head, Link } from '@inertiajs/react';
import { CalendarDays, Plus } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { create as payPeriodsCreate } from '@/routes/admin/pay-periods';

interface PayPeriodRow {
    id: number;
    code: string;
    frequency: 'monthly' | 'semi_monthly';
    start_date: string;
    end_date: string;
    cutoff_date: string | null;
    status: 'draft' | 'open' | 'closed';
}

interface Props {
    periods: PayPeriodRow[];
    can: { create: boolean };
}

const STATUS_VARIANT: Record<
    PayPeriodRow['status'],
    'default' | 'secondary' | 'outline'
> = {
    draft: 'outline',
    open: 'default',
    closed: 'secondary',
};

export default function PayPeriodsIndex({ periods, can }: Props) {
    return (
        <>
            <Head title="Pay periods" />
            <div className="space-y-6 p-4">
                <PageHeader
                    title="Pay periods"
                    description="Calendar windows admins can run payroll against. A period must be in `open` status to be selectable on the Generate payroll screen."
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link href={payPeriodsCreate().url}>
                                    <Plus className="mr-1 h-4 w-4" />
                                    New period
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {periods.length === 0 ? (
                    <Card>
                        <CardContent className="py-10">
                            <EmptyState
                                icon={CalendarDays}
                                title="No pay periods yet"
                                description="Create the first period (e.g. monthly 2026-05) so admins can generate payroll runs against it."
                                action={
                                    can.create ? (
                                        <Button asChild>
                                            <Link href={payPeriodsCreate().url}>
                                                Create period
                                            </Link>
                                        </Button>
                                    ) : undefined
                                }
                            />
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">
                                All periods
                            </CardTitle>
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
                                                Frequency
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Start
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                End
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Cutoff
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Status
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {periods.map((p) => (
                                            <TableRow key={p.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {p.code}
                                                </TableCell>
                                                <TableCell className="capitalize">
                                                    {p.frequency.replace(
                                                        '_',
                                                        '-',
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-xs tabular-nums">
                                                    {p.start_date}
                                                </TableCell>
                                                <TableCell className="text-xs tabular-nums">
                                                    {p.end_date}
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground tabular-nums">
                                                    {p.cutoff_date ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            STATUS_VARIANT[
                                                                p.status
                                                            ]
                                                        }
                                                    >
                                                        {p.status}
                                                    </Badge>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

PayPeriodsIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin/pay-periods' },
        { title: 'Pay periods', href: '/admin/pay-periods' },
    ],
};
