import { Badge } from '@/components/ui/badge';
import { PAYROLL_RUN_STATUS_LABELS } from '@/types/payroll-run';
import type { PayrollRunStatus } from '@/types/payroll-run';

const STATUS_VARIANT: Record<
    PayrollRunStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    draft: 'outline',
    computing: 'secondary',
    computed: 'default',
    pending_approval: 'secondary',
    approved: 'default',
    posted: 'default',
    voided: 'destructive',
};

function isKnownStatus(value: string): value is PayrollRunStatus {
    return value in STATUS_VARIANT;
}

export function PayrollRunStatusBadge({ status }: { status: string }) {
    const safe: PayrollRunStatus = isKnownStatus(status) ? status : 'draft';

    return (
        <Badge variant={STATUS_VARIANT[safe]}>
            {PAYROLL_RUN_STATUS_LABELS[safe]}
        </Badge>
    );
}
