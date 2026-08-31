import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { InvoiceForm } from '@/components/admin/invoice-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    index as invoiceIndex,
    show as invoiceShow,
} from '@/routes/admin/invoices';
import type { InvoiceEditable, InvoiceFormOptions } from '@/types';

interface Props extends InvoiceFormOptions {
    invoice: InvoiceEditable;
}

export default function InvoiceEdit({ invoice, ...options }: Props) {
    const isSales = invoice.type === 'sales';
    const title = `Edit draft ${isSales ? 'invoice' : 'bill'}`;

    return (
        <>
            <Head title={title} />

            <div className="mx-auto max-w-5xl space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={title}
                    description="Only a draft can be edited. Once approved, the document carries a number the customer has seen and is corrected by voiding it."
                    actions={
                        <Button asChild variant="outline">
                            <Link
                                href={invoiceShow({ invoice: invoice.id }).url}
                            >
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back
                            </Link>
                        </Button>
                    }
                />

                <InvoiceForm mode={{ kind: 'edit', invoice }} {...options} />
            </div>
        </>
    );
}

InvoiceEdit.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Invoices', href: invoiceIndex().url },
        { title: 'Edit', href: '#' },
    ],
};
