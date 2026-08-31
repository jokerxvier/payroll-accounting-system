import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { PaymentForm } from '@/components/admin/payment-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    index as paymentIndex,
    show as paymentShow,
} from '@/routes/admin/payments';
import type { PaymentEditable, PaymentFormOptions } from '@/types';

interface Props extends PaymentFormOptions {
    payment: PaymentEditable;
}

export default function PaymentEdit({ payment, ...options }: Props) {
    return (
        <>
            <Head title={`Edit draft payment #${payment.id}`} />

            <div className="mx-auto max-w-5xl space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={`Edit draft payment #${payment.id}`}
                    description="Only a draft can be edited. Once posted, the money is in the ledger and the documents are settled — correcting it means voiding and recording it again."
                    actions={
                        <Button asChild variant="outline">
                            <Link
                                href={paymentShow({ payment: payment.id }).url}
                            >
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back
                            </Link>
                        </Button>
                    }
                />

                <PaymentForm mode={{ kind: 'edit', payment }} {...options} />
            </div>
        </>
    );
}

PaymentEdit.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Payments', href: paymentIndex().url },
        { title: 'Edit', href: '#' },
    ],
};
