import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { DeductionTypeForm } from '@/components/admin/deduction-type-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { index as deductionTypesIndex } from '@/routes/admin/deduction-types';
import type { DeductionTypeRow } from '@/types';

interface Props {
    deductionType: DeductionTypeRow;
}

export default function DeductionTypesEdit({ deductionType }: Props) {
    return (
        <>
            <Head title={`Edit ${deductionType.code}`} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ADMIN"
                    title={`Edit '${deductionType.code}'`}
                    description={deductionType.name}
                    actions={
                        <Button asChild variant="outline">
                            <Link href={deductionTypesIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to list
                            </Link>
                        </Button>
                    }
                />

                <DeductionTypeForm mode={{ kind: 'edit', deductionType }} />
            </div>
        </>
    );
}

DeductionTypesEdit.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        { title: 'Deduction types', href: deductionTypesIndex().url },
        { title: 'Edit', href: '#' },
    ],
};
