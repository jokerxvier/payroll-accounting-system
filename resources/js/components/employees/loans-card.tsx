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
import type { EmployeeLoanRow } from '@/types';

interface LoansCardProps {
    loans: EmployeeLoanRow[];
    className?: string;
    onAdd?: () => void;
    onEdit?: (loan: EmployeeLoanRow) => void;
}

/**
 * List of a single employee's loans (open and closed). Compute path is
 * read-only at the UI level — amortization clamping is handled inside the
 * Phase 2 service composition. Add / Edit affordances are opt-in via callbacks.
 */
export function LoansCard({
    loans,
    className,
    onAdd,
    onEdit,
}: LoansCardProps) {
    return (
        <Card className={cn('lg:col-span-2', className)}>
            <CardHeader className="flex-row items-center justify-between">
                <CardTitle className="font-serif text-lg">Loans</CardTitle>
                {onAdd && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onAdd}
                        type="button"
                    >
                        <Plus className="mr-1 h-3.5 w-3.5" />
                        Add loan
                    </Button>
                )}
            </CardHeader>
            <CardContent>
                {loans.length === 0 ? (
                    <EmptyState
                        title="No loans on file"
                        description="Salary loans appear here with the running outstanding balance and per-period amortization."
                    />
                ) : (
                    <ul className="divide-y">
                        {loans.map((loan, index) => (
                            <li key={loan.id}>
                                {index > 0 && <Separator className="sr-only" />}
                                <LoanListItem loan={loan} onEdit={onEdit} />
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

interface LoanListItemProps {
    loan: EmployeeLoanRow;
    onEdit?: (loan: EmployeeLoanRow) => void;
}

function LoanListItem({ loan, onEdit }: LoanListItemProps) {
    const isClosed = loan.closed_on !== null;
    const percentPaid = computePercentPaid(loan);

    return (
        <div className="group space-y-3 py-3">
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-[2fr_1fr_1fr_1fr_auto] sm:items-center sm:gap-4">
                <div className="space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-mono text-sm font-medium">
                            {loan.code}
                        </p>
                        <LoanStatusBadge isClosed={isClosed} />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Started {formatDateSafe(loan.started_on)}
                        {isClosed && loan.closed_on
                            ? ` · closed ${formatDateSafe(loan.closed_on)}`
                            : ''}
                    </p>
                </div>

                <div className="text-sm tabular-nums sm:text-right">
                    <p className="text-xs text-muted-foreground">Amortization</p>
                    <Money amount={loan.monthly_amortization_centavos / 100} />
                </div>

                <div className="text-sm tabular-nums sm:text-right">
                    <p className="text-xs text-muted-foreground">Outstanding</p>
                    <Money amount={loan.outstanding_balance_centavos / 100} />
                </div>

                <div className="text-xs text-muted-foreground sm:text-right">
                    {SCHEDULE_LABEL[loan.schedule]}
                </div>

                {onEdit && (
                    <div className="flex justify-end sm:justify-center">
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => onEdit(loan)}
                            aria-label={`Edit loan ${loan.code}`}
                            className="h-8 w-8 p-0 opacity-60 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                        >
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                )}
            </div>

            <LoanProgressBar
                percentPaid={percentPaid}
                principalCentavos={loan.principal_centavos}
                outstandingCentavos={loan.outstanding_balance_centavos}
            />
        </div>
    );
}

function LoanStatusBadge({ isClosed }: { isClosed: boolean }) {
    if (isClosed) {
        return <Badge variant="outline">Closed</Badge>;
    }

    return (
        <Badge className="bg-success/15 text-success hover:bg-success/15">
            Open
        </Badge>
    );
}

interface LoanProgressBarProps {
    percentPaid: number;
    principalCentavos: number;
    outstandingCentavos: number;
}

function LoanProgressBar({
    percentPaid,
    principalCentavos,
    outstandingCentavos,
}: LoanProgressBarProps) {
    const widthStyle = { width: `${percentPaid}%` };
    const paidCentavos = Math.max(principalCentavos - outstandingCentavos, 0);

    return (
        <div className="space-y-1">
            <div
                role="progressbar"
                aria-label="Loan progress"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percentPaid}
                className="h-1.5 w-full overflow-hidden rounded-full bg-muted"
            >
                <div className="h-full bg-primary" style={widthStyle} />
            </div>
            <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span>
                    Paid <Money amount={paidCentavos / 100} /> of{' '}
                    <Money amount={principalCentavos / 100} />
                </span>
                <span className="tabular-nums">{percentPaid}%</span>
            </div>
        </div>
    );
}

function computePercentPaid(loan: EmployeeLoanRow): number {
    if (loan.principal_centavos <= 0) {
        return 0;
    }

    const paid = loan.principal_centavos - loan.outstanding_balance_centavos;
    const ratio = paid / loan.principal_centavos;
    const clamped = Math.min(Math.max(ratio, 0), 1);

    return Math.round(clamped * 100);
}

function formatDateSafe(value: string): string {
    try {
        return format(parseISO(value), 'MMM d, yyyy');
    } catch {
        return value;
    }
}
