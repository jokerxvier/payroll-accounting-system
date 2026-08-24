import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { AccountingPeriodForm } from '@/components/admin/accounting-period-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { index as periodsIndex } from '@/routes/admin/accounting-periods';
import type { AccountingPeriodEditable } from '@/types';

interface Props {
    period: AccountingPeriodEditable;
}

export default function AccountingPeriodsEdit({ period }: Props) {
    return (
        <>
            <Head title={`Edit ${period.code}`} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={period.name ?? period.code}
                    description="Only open periods can be edited. A closed period's boundaries are what every entry inside it was filed against."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={periodsIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to list
                            </Link>
                        </Button>
                    }
                />

                <AccountingPeriodForm mode={{ kind: 'edit', period }} />
            </div>
        </>
    );
}

AccountingPeriodsEdit.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Periods', href: periodsIndex().url },
        { title: 'Edit', href: '#' },
    ],
};
