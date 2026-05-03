import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { AllowanceForm } from '@/components/admin/allowance-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { index as allowancesIndex } from '@/routes/admin/allowances';
import type { AllowanceRow } from '@/types';

interface Props {
    allowance: AllowanceRow;
}

export default function AllowancesEdit({ allowance }: Props) {
    return (
        <>
            <Head title={`Edit ${allowance.code}`} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ADMIN"
                    title={`Edit '${allowance.code}'`}
                    description={allowance.name}
                    actions={
                        <Button asChild variant="outline">
                            <Link href={allowancesIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to list
                            </Link>
                        </Button>
                    }
                />

                <AllowanceForm mode={{ kind: 'edit', allowance }} />
            </div>
        </>
    );
}

AllowancesEdit.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        { title: 'Allowances', href: allowancesIndex().url },
        { title: 'Edit', href: '#' },
    ],
};
