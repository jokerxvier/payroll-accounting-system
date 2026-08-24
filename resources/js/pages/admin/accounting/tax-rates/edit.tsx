import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { TaxRateForm } from '@/components/admin/tax-rate-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { index as taxRatesIndex } from '@/routes/admin/tax-rates';
import type { AccountOption, TaxRateRow } from '@/types';

interface Props {
    taxRate: TaxRateRow;
    accountOptions: AccountOption[];
}

export default function TaxRatesEdit({ taxRate, accountOptions }: Props) {
    return (
        <>
            <Head title={`Edit ${taxRate.code}`} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={taxRate.name}
                    description="Edit this rate. Documents already issued keep the tax figures they were computed with."
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
                    mode={{ kind: 'edit', taxRate }}
                    accountOptions={accountOptions}
                />
            </div>
        </>
    );
}

TaxRatesEdit.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Tax rates', href: taxRatesIndex().url },
        { title: 'Edit', href: '#' },
    ],
};
