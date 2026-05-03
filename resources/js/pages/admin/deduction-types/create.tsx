import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { DeductionTypeForm } from '@/components/admin/deduction-type-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    create as deductionTypesCreate,
    index as deductionTypesIndex,
} from '@/routes/admin/deduction-types';

export default function DeductionTypesCreate() {
    return (
        <>
            <Head title="New deduction type" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ADMIN"
                    title="New deduction type"
                    description="Add a new row to the deduction type catalog. The code must be unique."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={deductionTypesIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to list
                            </Link>
                        </Button>
                    }
                />

                <DeductionTypeForm mode={{ kind: 'create' }} />
            </div>
        </>
    );
}

DeductionTypesCreate.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        { title: 'Deduction types', href: deductionTypesIndex().url },
        { title: 'New', href: deductionTypesCreate().url },
    ],
};
