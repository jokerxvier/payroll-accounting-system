import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { JournalEntryForm } from '@/components/admin/journal-entry-form';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { index as journalIndex } from '@/routes/admin/journal-entries';
import type { JournalAccountOption, JournalEntryEditable } from '@/types';

interface Props {
    entry: JournalEntryEditable;
    accountOptions: JournalAccountOption[];
}

export default function JournalEdit({ entry, accountOptions }: Props) {
    return (
        <>
            <Head title="Edit journal entry" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Edit draft entry"
                    description="Only drafts can be edited. Once posted, an entry is corrected by posting a reversal."
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
                    mode={{ kind: 'edit', entry }}
                    accountOptions={accountOptions}
                />
            </div>
        </>
    );
}

JournalEdit.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Journal', href: journalIndex().url },
        { title: 'Edit', href: '#' },
    ],
};
