import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useRef } from 'react';
import { PaymentForm } from '@/components/admin/payment-form';
import type { PaymentFormHandle } from '@/components/admin/payment-form';
import { DemoFillButton } from '@/components/dev/demo-fill-button';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    create as paymentCreate,
    index as paymentIndex,
} from '@/routes/admin/payments';
import type { PaymentFormOptions, PaymentType } from '@/types';

interface Props extends PaymentFormOptions {
    type: PaymentType;
}

export default function PaymentCreate({
    type,
    canDemoFill = false,
    ...options
}: Props) {
    const isReceipt = type === 'receipt';
    const title = isReceipt ? 'Record a receipt' : 'Record a payment';

    // The button sits in the header beside Back; the state it fills lives in
    // the form. A handle keeps both where they belong.
    const formRef = useRef<PaymentFormHandle>(null);

    return (
        <>
            <Head title={title} />

            <div className="mx-auto max-w-5xl space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={title}
                    description={
                        isReceipt
                            ? 'Saved as a draft first. Nothing reaches the ledger, and no invoice is settled, until you post it.'
                            : 'Saved as a draft first. Nothing reaches the ledger, and no bill is settled, until you post it.'
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
                                    href={paymentIndex({ query: { type } }).url}
                                >
                                    <ArrowLeft className="mr-1 h-4 w-4" />
                                    Back
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <PaymentForm
                    ref={formRef}
                    mode={{ kind: 'create', type }}
                    {...options}
                />
            </div>
        </>
    );
}

PaymentCreate.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Payments', href: paymentIndex().url },
        { title: 'New', href: paymentCreate().url },
    ],
};
