import { format, parseISO } from 'date-fns';
import { Pencil, Plus } from 'lucide-react';
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
import type { PayrollAdjustmentRow } from '@/types';

interface AdjustmentsCardProps {
    adjustments: PayrollAdjustmentRow[];
    className?: string;
    onAdd?: () => void;
    onEdit?: (row: PayrollAdjustmentRow) => void;
}

/**
 * List of one-off payroll adjustments (bonus, single deduction, back-pay
 * correction). Each row matches a payroll period at compute time via
 * `period.start() <= applies_on <= period.end()`. Add / Edit affordances are
 * opt-in via callbacks.
 */
export function AdjustmentsCard({
    adjustments,
    className,
    onAdd,
    onEdit,
}: AdjustmentsCardProps) {
    return (
        <Card className={cn('lg:col-span-2', className)}>
            <CardHeader className="flex-row items-center justify-between">
                <CardTitle className="font-serif text-lg">
                    Adjustments
                </CardTitle>
                {onAdd && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onAdd}
                        type="button"
                    >
                        <Plus className="mr-1 h-3.5 w-3.5" />
                        Add adjustment
                    </Button>
                )}
            </CardHeader>
            <CardContent>
                {adjustments.length === 0 ? (
                    <EmptyState
                        title="No adjustments"
                        description="One-off bonuses, single deductions, and back-pay corrections appear here, matched to a period by date."
                    />
                ) : (
                    <ul className="divide-y">
                        {adjustments.map((row, index) => (
                            <li key={row.id}>
                                {index > 0 && <Separator className="sr-only" />}
                                <AdjustmentListItem
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

interface AdjustmentListItemProps {
    row: PayrollAdjustmentRow;
    onEdit?: (row: PayrollAdjustmentRow) => void;
}

function AdjustmentListItem({ row, onEdit }: AdjustmentListItemProps) {
    const isAddition = row.kind === 'addition';
    const signedAmount = isAddition
        ? row.amount_centavos / 100
        : -row.amount_centavos / 100;

    return (
        <div className="group grid grid-cols-1 gap-2 py-3 sm:grid-cols-[1fr_2fr_1fr_1fr_auto] sm:items-center sm:gap-4">
            <div className="space-y-1">
                <p className="text-xs text-muted-foreground">
                    {formatDateSafe(row.applies_on)}
                </p>
                <AdjustmentKindBadge kind={row.kind} />
            </div>

            <div className="space-y-1">
                <p className="text-sm font-medium">{row.label}</p>
                {row.reason && (
                    <p className="line-clamp-2 text-xs text-muted-foreground">
                        {row.reason}
                    </p>
                )}
            </div>

            <div className="text-sm tabular-nums sm:text-right">
                <Money amount={signedAmount} signed />
            </div>

            <div className="text-xs text-muted-foreground sm:text-right">
                {row.is_taxable ? (
                    <Badge variant="secondary">Taxable</Badge>
                ) : (
                    <Badge variant="outline">Non-taxable</Badge>
                )}
            </div>

            {onEdit && (
                <div className="flex justify-end sm:justify-center">
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => onEdit(row)}
                        aria-label={`Edit ${row.label}`}
                        className="h-8 w-8 p-0 opacity-60 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                    >
                        <Pencil className="h-3.5 w-3.5" />
                    </Button>
                </div>
            )}
        </div>
    );
}

function AdjustmentKindBadge({ kind }: { kind: PayrollAdjustmentRow['kind'] }) {
    if (kind === 'addition') {
        return (
            <Badge className="bg-success/15 text-success hover:bg-success/15">
                Addition
            </Badge>
        );
    }

    return <Badge variant="destructive">Deduction</Badge>;
}

function formatDateSafe(value: string): string {
    try {
        return format(parseISO(value), 'MMM d, yyyy');
    } catch {
        return value;
    }
}
