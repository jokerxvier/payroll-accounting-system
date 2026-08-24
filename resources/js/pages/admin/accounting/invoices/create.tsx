import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { InvoiceForm } from '@/components/admin/invoice-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    create as invoiceCreate,
    index as invoiceIndex,
} from '@/routes/admin/invoices';
import type { InvoiceFormOptions, InvoiceType } from '@/types';

interface Props extends InvoiceFormOptions {
    type: InvoiceType;
}

export default function InvoiceCreate({ type, ...options }: Props) {
    const isSales = type === 'sales';
    const title = isSales ? 'New invoice' : 'New bill';

    return (
        <>
            <Head title={title} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={title}
                    description={
                        isSales
                            ? 'Saved as a draft first. It takes a number and reaches the ledger only when you approve it.'
                            : 'Records what a supplier has billed the school. Approving it posts the payable and the input VAT.'
                    }
                    actions={
                        <Button asChild variant="outline">
                            <Link href={invoiceIndex({ query: { type } }).url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to {isSales ? 'invoices' : 'bills'}
                            </Link>
                        </Button>
                    }
                />

                <InvoiceForm mode={{ kind: 'create', type }} {...options} />
            </div>
        </>
    );
}

InvoiceCreate.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Invoices', href: invoiceIndex().url },
        { title: 'New', href: invoiceCreate().url },
    ],
};
