import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Loader2 } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    index as payPeriodsIndex,
    store as payPeriodsStore,
} from '@/routes/admin/pay-periods';

export default function PayPeriodsCreate() {
    const { data, setData, post, processing, errors } = useForm({
        code: '',
        frequency: 'monthly',
        start_date: '',
        end_date: '',
        cutoff_date: '',
        status: 'open',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(payPeriodsStore().url);
    };

    return (
        <>
            <Head title="Create pay period" />
            <div className="mx-auto max-w-2xl space-y-6 p-4">
                <PageHeader
                    title="New pay period"
                    description="Defines a calendar window admins can later generate payroll against. Typical: monthly periods coded as `2026-05`."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={payPeriodsIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back
                            </Link>
                        </Button>
                    }
                />

                <form onSubmit={submit}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium">
                                Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="code">Code</Label>
                                <Input
                                    id="code"
                                    className="font-mono"
                                    placeholder="2026-05"
                                    value={data.code}
                                    onChange={(e) =>
                                        setData('code', e.target.value)
                                    }
                                    aria-invalid={
                                        errors.code ? 'true' : undefined
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Unique. Recommended:{' '}
                                    <span className="font-mono">YYYY-MM</span>{' '}
                                    for monthly,{' '}
                                    <span className="font-mono">YYYY-MM-A</span>{' '}
                                    or{' '}
                                    <span className="font-mono">YYYY-MM-B</span>{' '}
                                    for the two halves of a semi-monthly cutoff.
                                </p>
                                {errors.code ? (
                                    <p className="text-xs text-destructive">
                                        {errors.code}
                                    </p>
                                ) : null}
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="frequency">Frequency</Label>
                                    <Select
                                        value={data.frequency}
                                        onValueChange={(v) =>
                                            setData('frequency', v)
                                        }
                                    >
                                        <SelectTrigger id="frequency">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="monthly">
                                                Monthly
                                            </SelectItem>
                                            <SelectItem value="semi_monthly">
                                                Semi-monthly
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.frequency ? (
                                        <p className="text-xs text-destructive">
                                            {errors.frequency}
                                        </p>
                                    ) : null}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="status">Status</Label>
                                    <Select
                                        value={data.status}
                                        onValueChange={(v) =>
                                            setData('status', v)
                                        }
                                    >
                                        <SelectTrigger id="status">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="draft">
                                                Draft
                                            </SelectItem>
                                            <SelectItem value="open">
                                                Open
                                            </SelectItem>
                                            <SelectItem value="closed">
                                                Closed
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <p className="text-xs text-muted-foreground">
                                        Only{' '}
                                        <span className="font-mono">open</span>{' '}
                                        periods are selectable on the Generate
                                        payroll screen.
                                    </p>
                                </div>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="start_date">
                                        Start date
                                    </Label>
                                    <Input
                                        id="start_date"
                                        type="date"
                                        value={data.start_date}
                                        onChange={(e) =>
                                            setData(
                                                'start_date',
                                                e.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            errors.start_date
                                                ? 'true'
                                                : undefined
                                        }
                                    />
                                    {errors.start_date ? (
                                        <p className="text-xs text-destructive">
                                            {errors.start_date}
                                        </p>
                                    ) : null}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="end_date">End date</Label>
                                    <Input
                                        id="end_date"
                                        type="date"
                                        value={data.end_date}
                                        onChange={(e) =>
                                            setData('end_date', e.target.value)
                                        }
                                        aria-invalid={
                                            errors.end_date ? 'true' : undefined
                                        }
                                    />
                                    {errors.end_date ? (
                                        <p className="text-xs text-destructive">
                                            {errors.end_date}
                                        </p>
                                    ) : null}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="cutoff_date">
                                    Cutoff date{' '}
                                    <span className="text-muted-foreground">
                                        (optional)
                                    </span>
                                </Label>
                                <Input
                                    id="cutoff_date"
                                    type="date"
                                    value={data.cutoff_date}
                                    onChange={(e) =>
                                        setData('cutoff_date', e.target.value)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Latest date by which the resulting payroll
                                    run must be approved. Informational for now;
                                    enforcement lands in Week 10.
                                </p>
                                {errors.cutoff_date ? (
                                    <p className="text-xs text-destructive">
                                        {errors.cutoff_date}
                                    </p>
                                ) : null}
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-2">
                                <Button asChild type="button" variant="outline">
                                    <Link href={payPeriodsIndex().url}>
                                        Cancel
                                    </Link>
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? (
                                        <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                                    ) : null}
                                    Create period
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </>
    );
}

PayPeriodsCreate.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin/pay-periods' },
        { title: 'Pay periods', href: '/admin/pay-periods' },
        { title: 'New', href: '/admin/pay-periods/create' },
    ],
};
