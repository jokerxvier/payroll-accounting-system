import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { TaxRateForm } from '@/components/admin/tax-rate-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    create as taxRatesCreate,
    index as taxRatesIndex,
} from '@/routes/admin/tax-rates';
import type { AccountOption } from '@/types';

interface Props {
    accountOptions: AccountOption[];
}

export default function TaxRatesCreate({ accountOptions }: Props) {
    return (
        <>
            <Head title="New tax rate" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="New tax rate"
                    description="Add a rate for invoice and bill lines. The code must be unique within this school."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={taxRatesIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to list
                            </Link>
                        </Button>
                    }
                />

                <TaxRateForm
                    mode={{ kind: 'create' }}
                    accountOptions={accountOptions}
                />
            </div>
        </>
    );
}

TaxRatesCreate.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Tax rates', href: taxRatesIndex().url },
        { title: 'New', href: taxRatesCreate().url },
    ],
};
