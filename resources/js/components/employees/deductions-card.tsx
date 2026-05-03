import { format, parseISO } from 'date-fns';
import { Pencil, Plus } from 'lucide-react';
import { SCHEDULE_LABEL } from '@/components/employees/schedule-label';
import { EmptyState } from '@/components/empty-state';
import { Money } from '@/components/money';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    /** When provided, renders an "Add deduction" button in the card header. */
    onAdd?: () => void;
    /** When provided, renders a pencil icon button next to each row. */
    onEdit?: (row: EmployeeDeductionRow) => void;
}

/**
 * List of a single employee's active custom deduction subscriptions.
 * Add / Edit affordances are opt-in via callbacks so the component stays
 * usable as a pure-presentational read-only view (e.g. payslip reviews).
 */
export function DeductionsCard({
    deductions,
    className,
    onAdd,
    onEdit,
}: DeductionsCardProps) {
    return (
        <Card className={cn('lg:col-span-2', className)}>
            <CardHeader className="flex-row items-center justify-between">
                <CardTitle className="font-serif text-lg">
                    Custom deductions
                </CardTitle>
                {onAdd && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onAdd}
                        type="button"
                    >
                        <Plus className="mr-1 h-3.5 w-3.5" />
                        Add deduction
                    </Button>
                )}
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
                                <DeductionListItem
                                    row={row}
                                    onEdit={onEdit}
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

interface DeductionListItemProps {
    row: EmployeeDeductionRow;
    onEdit?: (row: EmployeeDeductionRow) => void;
}

function DeductionListItem({ row, onEdit }: DeductionListItemProps) {
    return (
        <div className="group grid grid-cols-1 gap-2 py-3 sm:grid-cols-[2fr_1fr_1fr_1fr_auto] sm:items-center sm:gap-4">
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
                    <Badge variant="outline" className="ml-2 align-middle">
                        Non-taxable
                    </Badge>
                )}
            </div>

            {onEdit && (
                <div className="flex justify-end sm:justify-center">
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => onEdit(row)}
                        aria-label={`Edit ${row.deduction_type.name}`}
                        className="h-8 w-8 p-0 opacity-60 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                    >
                        <Pencil className="h-3.5 w-3.5" />
                    </Button>
                </div>
            )}
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
