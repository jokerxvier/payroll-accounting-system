import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Loader2 } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    index as payrollRunsIndex,
    store as payrollRunsStore,
} from '@/routes/admin/payroll-runs';
import type { PayPeriodSummary } from '@/types/payroll-run';

interface Props {
    periods: PayPeriodSummary[];
}

export default function PayrollRunsCreate({ periods }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        pay_period_id: periods[0]?.id ? String(periods[0].id) : '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(payrollRunsStore().url);
    };

    return (
        <>
            <Head title="Generate payroll" />
            <div className="mx-auto max-w-2xl space-y-6 p-4">
                <PageHeader
                    title="Generate payroll"
                    description="Pick an open pay period. Every active employee will be queued for computation; payslips are persisted as each per-employee job lands."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={payrollRunsIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back
                            </Link>
                        </Button>
                    }
                />

                {periods.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-sm text-muted-foreground">
                            No open pay periods are available. Mark a period as
                            <span className="font-mono"> open</span> before
                            generating payroll.
                        </CardContent>
                    </Card>
                ) : (
                    <form onSubmit={submit}>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm font-medium">
                                    Period
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="pay_period_id">
                                        Pay period
                                    </Label>
                                    <Select
                                        value={data.pay_period_id}
                                        onValueChange={(v) =>
                                            setData('pay_period_id', v)
                                        }
                                    >
                                        <SelectTrigger
                                            id="pay_period_id"
                                            aria-invalid={
                                                errors.pay_period_id
                                                    ? 'true'
                                                    : undefined
                                            }
                                        >
                                            <SelectValue placeholder="Select a period" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {periods.map((p) => (
                                                <SelectItem
                                                    key={p.id}
                                                    value={String(p.id)}
                                                >
                                                    <span className="font-mono">
                                                        {p.code}
                                                    </span>
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        {p.start_date} →{' '}
                                                        {p.end_date}
                                                    </span>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.pay_period_id ? (
                                        <p className="text-xs text-destructive">
                                            {errors.pay_period_id}
                                        </p>
                                    ) : null}
                                </div>

                                <div className="flex items-center justify-end gap-2 pt-4">
                                    <Button
                                        asChild
                                        type="button"
                                        variant="outline"
                                    >
                                        <Link href={payrollRunsIndex().url}>
                                            Cancel
                                        </Link>
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing ||
                                            data.pay_period_id === ''
                                        }
                                    >
                                        {processing ? (
                                            <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                                        ) : null}
                                        Generate payroll
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </form>
                )}
            </div>
        </>
    );
}

PayrollRunsCreate.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '/admin/payroll-runs' },
        { title: 'Payroll runs', href: '/admin/payroll-runs' },
        { title: 'Generate', href: '/admin/payroll-runs/create' },
    ],
};
