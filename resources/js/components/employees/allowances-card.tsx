import { format, parseISO } from 'date-fns';
import { SCHEDULE_LABEL } from '@/components/employees/schedule-label';
import { EmptyState } from '@/components/empty-state';
import { Money } from '@/components/money';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import type { EmployeeAllowanceRow } from '@/types';

interface AllowancesCardProps {
    allowances: EmployeeAllowanceRow[];
    className?: string;
}

/**
 * Pure presentational list of a single employee's active allowance
 * subscriptions. One-off allowances ride on `pas_payroll_adjustments` and
 * surface in the adjustments card instead.
 */
export function AllowancesCard({ allowances, className }: AllowancesCardProps) {
    return (
        <Card className={cn('lg:col-span-2', className)}>
            <CardHeader>
                <CardTitle className="font-serif text-lg">Allowances</CardTitle>
            </CardHeader>
            <CardContent>
                {allowances.length === 0 ? (
                    <EmptyState
                        title="No active allowances"
                        description="Recurring allowances apply on the schedule shown next to each row."
                    />
                ) : (
                    <ul className="divide-y">
                        {allowances.map((row, index) => (
                            <li key={row.id}>
                                {index > 0 && <Separator className="sr-only" />}
                                <AllowanceListItem row={row} />
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function AllowanceListItem({ row }: { row: EmployeeAllowanceRow }) {
    return (
        <div className="grid grid-cols-1 gap-2 py-3 sm:grid-cols-[2fr_1fr_1fr_1fr] sm:items-center sm:gap-4">
            <div className="space-y-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-medium">{row.allowance.name}</p>
                    {row.allowance.is_de_minimis && (
                        <Badge variant="secondary">De-minimis</Badge>
                    )}
                </div>
                <p className="font-mono text-xs text-muted-foreground">
                    {row.allowance.code}
                </p>
            </div>

            <div className="text-sm tabular-nums sm:text-right">
                <Money amount={row.amount_centavos / 100} />
            </div>

            <div className="text-xs text-muted-foreground sm:text-right">
                {SCHEDULE_LABEL[row.schedule]}
            </div>

            <div className="text-xs text-muted-foreground sm:text-right">
                <EffectiveRange
                    from={row.effective_from}
                    to={row.effective_to}
                />
                {!row.allowance.is_taxable && (
                    <Badge variant="outline" className="ml-2 align-middle">
                        Non-taxable
                    </Badge>
                )}
            </div>
        </div>
    );
}

function EffectiveRange({ from, to }: { from: string; to: string | null }) {
    const fromLabel = formatDateSafe(from);
    const toLabel = to ? formatDateSafe(to) : 'open';

    return (
        <span>
            {fromLabel} → {toLabel}
        </span>
    );
}

function formatDateSafe(value: string): string {
    try {
        return format(parseISO(value), 'MMM d, yyyy');
    } catch {
        return value;
    }
}
