import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useRef } from 'react';
import { InvoiceForm } from '@/components/admin/invoice-form';
import type { InvoiceFormHandle } from '@/components/admin/invoice-form';
import { DemoFillButton } from '@/components/dev/demo-fill-button';
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

export default function InvoiceCreate({
    type,
    canDemoFill = false,
    ...options
}: Props) {
    const isSales = type === 'sales';
    const title = isSales ? 'New invoice' : 'New bill';

    // The button sits in the header beside Back, but the state it fills lives
    // in the form. A handle is the smallest seam that keeps both where they
    // belong.
    const formRef = useRef<InvoiceFormHandle>(null);

    return (
        <>
            <Head title={title} />

            <div className="mx-auto max-w-5xl space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={title}
                    description={
                        isSales
                            ? 'Numbered and saved as a draft first. It reaches the ledger only when you approve it.'
                            : 'Records what a supplier has billed the school. Approving it posts the payable and the input VAT.'
                    }
                    actions={
                        <div className="flex items-center gap-2">
                            {canDemoFill && (
                                <DemoFillButton
                                    onFill={() =>
                                        formRef.current?.fillWithDemoData()
                                    }
                                />
                            )}
                            <Button asChild variant="outline">
                                <Link
                                    href={invoiceIndex({ query: { type } }).url}
                                >
                                    <ArrowLeft className="mr-1 h-4 w-4" />
                                    Back to {isSales ? 'invoices' : 'bills'}
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <InvoiceForm
                    ref={formRef}
                    mode={{ kind: 'create', type }}
                    {...options}
                />
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
