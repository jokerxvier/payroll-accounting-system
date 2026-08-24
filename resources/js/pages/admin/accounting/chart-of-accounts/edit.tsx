import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { ChartOfAccountForm } from '@/components/admin/chart-of-account-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { index as accountsIndex } from '@/routes/admin/chart-of-accounts';
import type { AccountOption, ChartOfAccountRow } from '@/types';

interface Props {
    account: ChartOfAccountRow;
    parentOptions: AccountOption[];
}

export default function ChartOfAccountsEdit({ account, parentOptions }: Props) {
    return (
        <>
            <Head title={`Edit ${account.code}`} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={`${account.code} — ${account.name}`}
                    description="Edit this account. Changes apply to future postings; entries already posted keep the figures they were written with."
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
                    mode={{ kind: 'edit', account }}
                    parentOptions={parentOptions}
                />
            </div>
        </>
    );
}

ChartOfAccountsEdit.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Chart of accounts', href: accountsIndex().url },
        { title: 'Edit', href: '#' },
    ],
};
