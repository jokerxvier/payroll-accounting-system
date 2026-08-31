import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { RecurringInvoiceForm } from '@/components/admin/recurring-invoice-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { index as scheduleIndex } from '@/routes/admin/recurring-invoices';
import type {
    RecurringInvoiceEditable,
    RecurringInvoiceFormOptions,
} from '@/types';

interface Props extends RecurringInvoiceFormOptions {
    schedule: RecurringInvoiceEditable;
}

export default function RecurringInvoiceEdit({ schedule, ...options }: Props) {
    return (
        <>
            <Head title={`Edit ${schedule.name}`} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title={schedule.name}
                    description="Changes apply to invoices this schedule raises from now on. Ones it has already raised are documents and are not rewritten."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={scheduleIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to schedules
                            </Link>
                        </Button>
                    }
                />

                <RecurringInvoiceForm
                    mode={{ kind: 'edit', schedule }}
                    {...options}
                />
            </div>
        </>
    );
}

RecurringInvoiceEdit.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Recurring invoices', href: scheduleIndex().url },
        { title: 'Edit', href: '#' },
    ],
};
