import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { AccountingPeriodForm } from '@/components/admin/accounting-period-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    create as periodsCreate,
    index as periodsIndex,
} from '@/routes/admin/accounting-periods';

export default function AccountingPeriodsCreate() {
    return (
        <>
            <Head title="New accounting period" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="New accounting period"
                    description="Pick the start date and the rest fills in for a calendar month. Adjust any field for a non-calendar fiscal year."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={periodsIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to list
                            </Link>
                        </Button>
                    }
                />

                <AccountingPeriodForm mode={{ kind: 'create' }} />
            </div>
        </>
    );
}

AccountingPeriodsCreate.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Periods', href: periodsIndex().url },
        { title: 'New', href: periodsCreate().url },
    ],
};
