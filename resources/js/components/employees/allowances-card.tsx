import { format, parseISO } from 'date-fns';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { SCHEDULE_LABEL } from '@/components/employees/schedule-label';
import { EmptyState } from '@/components/empty-state';
import { Money } from '@/components/money';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import type { EmployeeAllowanceRow } from '@/types';

interface AllowancesCardProps {
    allowances: EmployeeAllowanceRow[];
    className?: string;
    onAdd?: () => void;
    onEdit?: (row: EmployeeAllowanceRow) => void;
    onDelete?: (row: EmployeeAllowanceRow) => void;
}

/**
 * List of a single employee's active allowance subscriptions. One-off
 * allowances ride on `pas_payroll_adjustments` and surface in the adjustments
 * card instead. Add / Edit affordances are opt-in via callbacks.
 */
export function AllowancesCard({
    allowances,
    className,
    onAdd,
    onEdit,
    onDelete,
}: AllowancesCardProps) {
    return (
        <Card className={cn('lg:col-span-2', className)}>
            <CardHeader className="flex-row items-center justify-between">
                <CardTitle className="font-serif text-lg">Allowances</CardTitle>
                {onAdd && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onAdd}
                        type="button"
                    >
                        <Plus className="mr-1 h-3.5 w-3.5" />
                        Add allowance
                    </Button>
                )}
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
                                <AllowanceListItem
                                    row={row}
                                    onEdit={onEdit}
                                    onDelete={onDelete}
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

interface AllowanceListItemProps {
    row: EmployeeAllowanceRow;
    onEdit?: (row: EmployeeAllowanceRow) => void;
    onDelete?: (row: EmployeeAllowanceRow) => void;
}

function AllowanceListItem({ row, onEdit, onDelete }: AllowanceListItemProps) {
    const hasActions = Boolean(onEdit) || Boolean(onDelete);

    return (
        <div className="group grid grid-cols-1 gap-2 py-3 sm:grid-cols-[2fr_1fr_1fr_1fr_auto] sm:items-center sm:gap-4">
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

            {hasActions && (
                <div className="flex justify-end gap-1 sm:justify-center">
                    {onEdit && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => onEdit(row)}
                            aria-label={`Edit ${row.allowance.name}`}
                            className="h-8 w-8 p-0 opacity-60 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                        >
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                    )}
                    {onDelete && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => onDelete(row)}
                            aria-label={`Delete allowance ${row.allowance.name}`}
                            className="h-8 w-8 p-0 text-destructive opacity-60 transition-opacity group-hover:opacity-100 hover:bg-destructive/10 hover:text-destructive focus-visible:opacity-100"
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    )}
                </div>
            )}
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
