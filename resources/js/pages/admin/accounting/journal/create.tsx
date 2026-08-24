import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { JournalEntryForm } from '@/components/admin/journal-entry-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    create as journalCreate,
    index as journalIndex,
} from '@/routes/admin/journal-entries';
import type { JournalAccountOption } from '@/types';

interface Props {
    accountOptions: JournalAccountOption[];
}

export default function JournalCreate({ accountOptions }: Props) {
    return (
        <>
            <Head title="New journal entry" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="New journal entry"
                    description="Saved as a draft first. Nothing reaches the ledger until you post it."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={journalIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to journal
                            </Link>
                        </Button>
                    }
                />

                <JournalEntryForm
                    mode={{ kind: 'create' }}
                    accountOptions={accountOptions}
                />
            </div>
        </>
    );
}

JournalCreate.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Journal', href: journalIndex().url },
        { title: 'New', href: journalCreate().url },
    ],
};
