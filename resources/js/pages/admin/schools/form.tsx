import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { SchoolForm } from '@/components/admin/school-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { index as schoolsIndex } from '@/routes/admin/schools';
import type { School } from '@/types';

interface Props {
    school: School | null;
    mode: 'create' | 'edit';
}

export default function SchoolFormPage({ school, mode }: Props) {
    const isEdit = mode === 'edit' && school !== null;

    const title = isEdit ? `Edit '${school.name}'` : 'New school';
    const description = isEdit
        ? school.slug
        : 'Register a new tenant. The slug must be unique and the LMS connection must be reachable before payroll can run for this school.';

    return (
        <>
            <Head title={title} />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ADMIN"
                    title={title}
                    description={description}
                    actions={
                        <Button asChild variant="outline">
                            <Link href={schoolsIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to list
                            </Link>
                        </Button>
                    }
                />

                {isEdit ? (
                    <SchoolForm mode={{ kind: 'edit', school }} />
                ) : (
                    <SchoolForm mode={{ kind: 'create' }} />
                )}
            </div>
        </>
    );
}

SchoolFormPage.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        { title: 'Schools', href: schoolsIndex().url },
        { title: 'Form', href: '#' },
    ],
};
