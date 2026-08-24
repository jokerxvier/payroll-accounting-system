import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ChartOfAccountForm } from '@/components/admin/chart-of-account-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    create as accountsCreate,
    index as accountsIndex,
} from '@/routes/admin/chart-of-accounts';
import type { AccountOption } from '@/types';

interface Props {
    parentOptions: AccountOption[];
}

export default function ChartOfAccountsCreate({ parentOptions }: Props) {
    return (
        <>
            <Head title="New account" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="New account"
                    description="Add an account to the chart. The code must be unique within this school."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={accountsIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to chart
                            </Link>
                        </Button>
                    }
                />

                <ChartOfAccountForm
                    mode={{ kind: 'create' }}
                    parentOptions={parentOptions}
                />
            </div>
        </>
    );
}

ChartOfAccountsCreate.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Chart of accounts', href: accountsIndex().url },
        { title: 'New', href: accountsCreate().url },
    ],
};
