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
import type { EmployeeDeductionRow } from '@/types';

interface DeductionsCardProps {
    deductions: EmployeeDeductionRow[];
    className?: string;
}

/**
 * Pure presentational list of a single employee's active custom deduction
 * subscriptions. Edit / Add wiring lands with full Chunk 6 once nested
 * controllers exist.
 */
export function DeductionsCard({ deductions, className }: DeductionsCardProps) {
    return (
        <Card className={cn('lg:col-span-2', className)}>
            <CardHeader>
                <CardTitle className="font-serif text-lg">
                    Custom deductions
                </CardTitle>
            </CardHeader>
            <CardContent>
                {deductions.length === 0 ? (
                    <EmptyState
                        title="No custom deductions"
                        description="Subscriptions added here apply on top of statutory contributions (SSS, PhilHealth, Pag-IBIG, BIR)."
                    />
                ) : (
                    <ul className="divide-y">
                        {deductions.map((row, index) => (
                            <li key={row.id}>
                                {index > 0 && <Separator className="sr-only" />}
                                <DeductionListItem row={row} />
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function DeductionListItem({ row }: { row: EmployeeDeductionRow }) {
    return (
        <div className="grid grid-cols-1 gap-2 py-3 sm:grid-cols-[2fr_1fr_1fr_1fr] sm:items-center sm:gap-4">
            <div className="space-y-1">
                <p className="text-sm font-medium">{row.deduction_type.name}</p>
                <p className="font-mono text-xs text-muted-foreground">
                    {row.deduction_type.code}
                </p>
            </div>

            <div className="text-sm tabular-nums sm:text-right">
                <DeductionAmount row={row} />
            </div>

            <div className="text-xs text-muted-foreground sm:text-right">
                {SCHEDULE_LABEL[row.schedule]}
            </div>

            <div className="text-xs text-muted-foreground sm:text-right">
                <EffectiveRange
                    from={row.effective_from}
                    to={row.effective_to}
                />
                {!row.deduction_type.is_taxable && (
                    <Badge variant="secondary" className="ml-2 align-middle">
                        Non-taxable
                    </Badge>
                )}
            </div>
        </div>
    );
}

function DeductionAmount({ row }: { row: EmployeeDeductionRow }) {
    if (row.deduction_type.calc_method === 'percent') {
        const bp = row.percent_basis_points ?? 0;

        return <span>{(bp / 100).toFixed(2)}%</span>;
    }

    const centavos = row.amount_centavos ?? 0;

    return <Money amount={centavos / 100} />;
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
