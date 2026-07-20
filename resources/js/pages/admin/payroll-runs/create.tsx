import { Head, Link, useForm } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { ArrowLeft, Calendar, Check, Info, Loader2, Lock } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import {
    index as payrollRunsIndex,
    store as payrollRunsStore,
} from '@/routes/admin/payroll-runs';
import { PAYROLL_RUN_STATUS_LABELS } from '@/types/payroll-run';
import type { PayPeriodOption } from '@/types/payroll-run';

interface Props {
    periods: PayPeriodOption[];
    active_employee_count: number;
}

const FREQUENCY_LABELS: Record<PayPeriodOption['frequency'], string> = {
    monthly: 'Monthly',
    semi_monthly: 'Semi-monthly',
};

/**
 * Render a period's start/end as a compact human range, collapsing a shared
 * month or year: "May 1 – 15, 2026", "Apr 28 – May 3, 2026".
 */
function formatPeriodRange(start: string, end: string): string {
    const from = parseISO(start);
    const to = parseISO(end);
    const sameMonth =
        from.getFullYear() === to.getFullYear() &&
        from.getMonth() === to.getMonth();
    const sameYear = from.getFullYear() === to.getFullYear();

    if (sameMonth) {
        return `${format(from, 'MMM d')} – ${format(to, 'd, yyyy')}`;
    }

    if (sameYear) {
        return `${format(from, 'MMM d')} – ${format(to, 'MMM d, yyyy')}`;
    }

    return `${format(from, 'MMM d, yyyy')} – ${format(to, 'MMM d, yyyy')}`;
}

export default function PayrollRunsCreate({
    periods,
    active_employee_count,
}: Props) {
    // Locked periods (an approved/posted run already exists) can't be the
    // default — pick the first selectable one instead.
    const firstSelectable = periods.find((p) => p.locked_by_run === null);
    const noneSelectable = periods.length > 0 && firstSelectable === undefined;

    const { data, setData, post, processing, errors } = useForm({
        pay_period_id: firstSelectable ? String(firstSelectable.id) : '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(payrollRunsStore().url);
    };

    const headcount =
        active_employee_count === 1
            ? '1 active employee'
            : `${active_employee_count} active employees`;

    return (
        <>
            <Head title="Generate payroll" />
            <div className="mx-auto max-w-2xl space-y-6 p-4">
                <PageHeader
                    eyebrow="Payroll runs"
                    title="Generate payroll"
                    description="Pick an open pay period, then queue every active employee for computation."
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
                        <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
                            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <Calendar className="h-5 w-5" />
                            </span>
                            <div className="space-y-1">
                                <p className="text-sm font-medium">
                                    No open pay periods
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Mark a period as{' '}
                                    <span className="font-mono">open</span>{' '}
                                    before you can generate payroll.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <form onSubmit={submit}>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Choose a pay period
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <fieldset
                                    aria-describedby={
                                        errors.pay_period_id
                                            ? 'pay-period-error'
                                            : 'pay-period-help'
                                    }
                                    aria-invalid={
                                        errors.pay_period_id ? true : undefined
                                    }
                                >
                                    <legend className="sr-only">
                                        Pay period
                                    </legend>
                                    <div className="space-y-2">
                                        {periods.map((p) => {
                                            const range = formatPeriodRange(
                                                p.start_date,
                                                p.end_date,
                                            );

                                            if (p.locked_by_run) {
                                                const statusLabel =
                                                    PAYROLL_RUN_STATUS_LABELS[
                                                        p.locked_by_run.status
                                                    ];

                                                return (
                                                    <div
                                                        key={p.id}
                                                        aria-label={`${FREQUENCY_LABELS[p.frequency]} period ${p.code}, ${range}. Locked — already has run number ${p.locked_by_run.id}, ${statusLabel}.`}
                                                        className="rounded-lg border border-dashed border-border bg-muted/30 px-4 py-3 opacity-80"
                                                    >
                                                        <div className="flex items-center justify-between gap-2">
                                                            <div className="flex items-center gap-2">
                                                                <Badge variant="secondary">
                                                                    {
                                                                        FREQUENCY_LABELS[
                                                                            p
                                                                                .frequency
                                                                        ]
                                                                    }
                                                                </Badge>
                                                                <span className="font-mono text-sm text-muted-foreground">
                                                                    {p.code}
                                                                </span>
                                                            </div>
                                                            <Badge
                                                                variant="outline"
                                                                className="gap-1 text-muted-foreground"
                                                            >
                                                                <Lock className="h-3 w-3" />
                                                                Locked
                                                            </Badge>
                                                        </div>
                                                        <p className="mt-1.5 text-sm text-muted-foreground">
                                                            {range}
                                                        </p>
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            Already has run #
                                                            {p.locked_by_run.id}{' '}
                                                            · {statusLabel}
                                                        </p>
                                                    </div>
                                                );
                                            }

                                            const isSelected =
                                                data.pay_period_id ===
                                                String(p.id);

                                            return (
                                                <label
                                                    key={p.id}
                                                    className={cn(
                                                        'flex cursor-pointer items-center justify-between gap-3 rounded-lg border px-4 py-3 transition-colors',
                                                        'has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2',
                                                        isSelected
                                                            ? 'border-primary bg-primary/5'
                                                            : 'border-border hover:bg-muted/40',
                                                    )}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="pay_period_id"
                                                        value={String(p.id)}
                                                        checked={isSelected}
                                                        onChange={() =>
                                                            setData(
                                                                'pay_period_id',
                                                                String(p.id),
                                                            )
                                                        }
                                                        className="sr-only"
                                                    />
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-2">
                                                            <Badge variant="secondary">
                                                                {
                                                                    FREQUENCY_LABELS[
                                                                        p
                                                                            .frequency
                                                                    ]
                                                                }
                                                            </Badge>
                                                            <span className="font-mono text-sm">
                                                                {p.code}
                                                            </span>
                                                        </div>
                                                        <p className="mt-1.5 text-sm text-muted-foreground">
                                                            {range}
                                                        </p>
                                                    </div>
                                                    <span
                                                        aria-hidden="true"
                                                        className={cn(
                                                            'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border',
                                                            isSelected
                                                                ? 'border-primary bg-primary text-primary-foreground'
                                                                : 'border-input',
                                                        )}
                                                    >
                                                        {isSelected ? (
                                                            <Check className="h-3.5 w-3.5" />
                                                        ) : null}
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                </fieldset>

                                {noneSelectable ? (
                                    <p
                                        role="status"
                                        className="text-sm text-muted-foreground"
                                    >
                                        Every open period already has a run.
                                        Void or delete one to generate again.
                                    </p>
                                ) : (
                                    <div
                                        id="pay-period-help"
                                        className="flex gap-2.5 rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground"
                                    >
                                        <Info className="mt-0.5 h-4 w-4 shrink-0" />
                                        <p>
                                            Generating queues{' '}
                                            <span className="font-medium text-foreground tabular-nums">
                                                {headcount}
                                            </span>{' '}
                                            for this period. Payslips appear as
                                            each job finishes.
                                        </p>
                                    </div>
                                )}

                                {errors.pay_period_id ? (
                                    <p
                                        id="pay-period-error"
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {errors.pay_period_id}
                                    </p>
                                ) : null}
                            </CardContent>
                            <CardFooter className="justify-end gap-2">
                                <Button asChild type="button" variant="outline">
                                    <Link href={payrollRunsIndex().url}>
                                        Cancel
                                    </Link>
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={
                                        processing || data.pay_period_id === ''
                                    }
                                >
                                    {processing ? (
                                        <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                                    ) : null}
                                    Generate payroll
                                </Button>
                            </CardFooter>
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
