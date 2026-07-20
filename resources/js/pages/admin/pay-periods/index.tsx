import { Head, Link, useForm } from '@inertiajs/react';
import { CalendarDays, Loader2, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    create as payPeriodsCreate,
    update as payPeriodsUpdate,
} from '@/routes/admin/pay-periods';

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
    can: { create: boolean; update: boolean };
}

const STATUS_VARIANT: Record<
    PayPeriodRow['status'],
    'default' | 'secondary' | 'outline'
> = {
    draft: 'outline',
    open: 'default',
    closed: 'secondary',
};

const STATUS_OPTIONS: { value: PayPeriodRow['status']; label: string }[] = [
    { value: 'draft', label: 'Draft' },
    { value: 'open', label: 'Open' },
    { value: 'closed', label: 'Closed' },
];

export default function PayPeriodsIndex({ periods, can }: Props) {
    const [editing, setEditing] = useState<PayPeriodRow | null>(null);
    const { data, setData, patch, processing, errors, clearErrors, reset } =
        useForm<{ status: PayPeriodRow['status'] }>({ status: 'open' });

    const openEdit = (period: PayPeriodRow) => {
        clearErrors();
        setData('status', period.status);
        setEditing(period);
    };

    const closeEdit = () => {
        setEditing(null);
        reset();
        clearErrors();
    };

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!editing) {
            return;
        }

        patch(payPeriodsUpdate({ payPeriod: editing.id }).url, {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

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
                                            {can.update ? <TableHead /> : null}
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
                                                        className="capitalize"
                                                    >
                                                        {p.status}
                                                    </Badge>
                                                </TableCell>
                                                {can.update ? (
                                                    <TableCell className="text-right">
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            className="h-7 w-7"
                                                            aria-label={`Edit ${p.code} status`}
                                                            onClick={() =>
                                                                openEdit(p)
                                                            }
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Button>
                                                    </TableCell>
                                                ) : null}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        closeEdit();
                    }
                }}
            >
                <DialogContent>
                    <form onSubmit={submitEdit}>
                        <DialogHeader>
                            <DialogTitle>Edit period status</DialogTitle>
                            <DialogDescription>
                                Manually set the status for{' '}
                                <span className="font-mono">
                                    {editing?.code}
                                </span>
                                . This overrides the automatic open/closed
                                handling until the next payroll action.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-2 py-4">
                            <Label htmlFor="status">Status</Label>
                            <Select
                                value={data.status}
                                onValueChange={(v) =>
                                    setData(
                                        'status',
                                        v as PayPeriodRow['status'],
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="status"
                                    aria-invalid={
                                        errors.status ? 'true' : undefined
                                    }
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {STATUS_OPTIONS.map((o) => (
                                        <SelectItem
                                            key={o.value}
                                            value={o.value}
                                        >
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.status ? (
                                <p className="text-xs text-destructive">
                                    {errors.status}
                                </p>
                            ) : null}
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={closeEdit}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? (
                                    <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                                ) : null}
                                Save status
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

PayPeriodsIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin/pay-periods' },
        { title: 'Pay periods', href: '/admin/pay-periods' },
    ],
};
