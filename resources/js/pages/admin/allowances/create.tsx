import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { AllowanceForm } from '@/components/admin/allowance-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    create as allowancesCreate,
    index as allowancesIndex,
} from '@/routes/admin/allowances';

export default function AllowancesCreate() {
    return (
        <>
            <Head title="New allowance" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ADMIN"
                    title="New allowance"
                    description="Add a new row to the allowance catalog. The code must be unique."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={allowancesIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to list
                            </Link>
                        </Button>
                    }
                />

                <AllowanceForm mode={{ kind: 'create' }} />
            </div>
        </>
    );
}

AllowancesCreate.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        { title: 'Allowances', href: allowancesIndex().url },
        { title: 'New', href: allowancesCreate().url },
    ],
};
