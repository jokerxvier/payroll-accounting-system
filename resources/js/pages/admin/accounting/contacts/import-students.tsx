import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    Check,
    GraduationCap,
    RefreshCw,
    UserPlus,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { index as contactsIndex } from '@/routes/admin/contacts';

/**
 * Importing students from the school records.
 *
 * Parents are the payers and become the contacts; students are who the
 * charges are for and get linked to their payer. The preview walks the
 * student roster one row per student, so every active student is visible,
 * including one with no parent record, which is shown as an error and
 * skipped rather than quietly dropped. Nothing is written until confirmed.
 */

interface StudentRow {
    lms_student_id: number;
    student_name: string;
    lms_parent_id: number | null;
    payer_name: string | null;
    payer_email: string | null;
    payer_phone: string | null;
    payer_address: string | null;
    relationship: string | null;
    existing_contact_id: number | null;
    existing_contact_name: string | null;
    action: 'create' | 'link' | 'unchanged';
    errors: string[];
}

interface Props {
    parsed?: StudentRow[] | null;
    token?: string | null;
    summary?: {
        students: number;
        payers: number;
        unchanged: number;
        errors: number;
    } | null;
}

const ACTION_LABELS: Record<StudentRow['action'], string> = {
    create: 'New payer contact',
    link: 'Link to payer',
    unchanged: 'Already imported',
};

export default function ImportStudents({ parsed, token, summary }: Props) {
    const scan = useForm({});
    const confirm = useForm({});

    const runScan = (e: FormEvent) => {
        e.preventDefault();
        scan.post('/admin/contacts/import-students/preview');
    };

    const runConfirm = (e: FormEvent) => {
        e.preventDefault();

        if (token) {
            confirm.post(`/admin/contacts/import-students/confirm/${token}`);
        }
    };

    const applicable = summary?.students ?? 0;

    return (
        <>
            <Head title="Import students" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Import students"
                    description="Bring the school's students in, each linked to the parent or guardian who pays for them. The payer becomes the contact — one contact per family, however many children."
                    actions={
                        <Button asChild variant="outline">
                            <Link href={contactsIndex()}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to contacts
                            </Link>
                        </Button>
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle>1. Read the school records</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Nothing is written until you confirm. This only
                            reads — the school records are never modified.
                        </p>
                        <form onSubmit={runScan}>
                            <Button type="submit" disabled={scan.processing}>
                                <RefreshCw
                                    className={cn(
                                        'mr-1 h-4 w-4',
                                        scan.processing && 'animate-spin',
                                    )}
                                />
                                {parsed ? 'Read again' : 'Read school records'}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {parsed && summary && (
                    <Card>
                        <CardHeader>
                            <CardTitle>2. Review and import</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="flex flex-wrap gap-4 rounded-lg border p-4">
                                <Stat
                                    icon={
                                        <GraduationCap className="size-4 text-muted-foreground" />
                                    }
                                    label="Students to link"
                                    value={summary.students}
                                />
                                <Stat
                                    icon={
                                        <UserPlus className="size-4 text-muted-foreground" />
                                    }
                                    label="New payer contacts"
                                    value={summary.payers}
                                />
                                <Stat
                                    icon={
                                        <Check className="size-4 text-muted-foreground" />
                                    }
                                    label="Already imported"
                                    value={summary.unchanged}
                                />
                            </div>

                            {summary.errors > 0 && (
                                <Alert variant="destructive">
                                    <AlertCircle className="size-4" />
                                    <AlertTitle>
                                        {summary.errors} student
                                        {summary.errors === 1 ? '' : 's'} need
                                        {summary.errors === 1 ? 's' : ''}{' '}
                                        attention
                                    </AlertTitle>
                                    <AlertDescription>
                                        They are listed below and will be
                                        skipped. Everything else still imports.
                                    </AlertDescription>
                                </Alert>
                            )}

                            {parsed.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No active students were found in the school
                                    system.
                                </p>
                            ) : (
                                <div className="rounded-md border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Student</TableHead>
                                                <TableHead>Payer</TableHead>
                                                <TableHead>
                                                    Contact details
                                                </TableHead>
                                                <TableHead className="w-44">
                                                    Action
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {parsed.map((row) => (
                                                <TableRow
                                                    key={row.lms_student_id}
                                                    className={cn(
                                                        row.errors.length > 0 &&
                                                            'bg-destructive/5',
                                                    )}
                                                >
                                                    <TableCell className="align-top font-medium">
                                                        {row.student_name}
                                                    </TableCell>
                                                    <TableCell className="align-top">
                                                        <div className="font-medium">
                                                            {row.payer_name ??
                                                                '—'}
                                                        </div>
                                                        {row.relationship && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {
                                                                    row.relationship
                                                                }
                                                            </div>
                                                        )}
                                                        {row.errors.map(
                                                            (error, i) => (
                                                                <p
                                                                    key={i}
                                                                    className="mt-1 text-sm text-destructive"
                                                                >
                                                                    {error}
                                                                </p>
                                                            ),
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="align-top text-sm text-muted-foreground">
                                                        {row.payer_email ??
                                                            row.payer_phone ??
                                                            '—'}
                                                    </TableCell>
                                                    <TableCell className="align-top">
                                                        <Badge
                                                            variant={
                                                                row.errors
                                                                    .length > 0
                                                                    ? 'destructive'
                                                                    : row.action ===
                                                                        'unchanged'
                                                                      ? 'outline'
                                                                      : 'default'
                                                            }
                                                        >
                                                            {row.errors.length >
                                                            0
                                                                ? 'Skipped'
                                                                : ACTION_LABELS[
                                                                      row.action
                                                                  ]}
                                                        </Badge>
                                                        {row.existing_contact_name && (
                                                            <p className="mt-1 text-xs text-muted-foreground">
                                                                {
                                                                    row.existing_contact_name
                                                                }
                                                            </p>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}

                            <form onSubmit={runConfirm}>
                                <Button
                                    type="submit"
                                    disabled={
                                        confirm.processing || applicable === 0
                                    }
                                >
                                    {applicable === 0
                                        ? 'Nothing to import'
                                        : `Import ${applicable} student${applicable === 1 ? '' : 's'}`}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

function Stat({
    icon,
    label,
    value,
}: {
    icon: React.ReactNode;
    label: string;
    value: number;
}) {
    return (
        <div className="flex items-center gap-2">
            {icon}
            <div>
                <div className="text-lg font-medium tabular-nums">{value}</div>
                <div className="text-xs text-muted-foreground">{label}</div>
            </div>
        </div>
    );
}

ImportStudents.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/journal-entries' },
        { title: 'Contacts', href: '/admin/contacts' },
        { title: 'Import students', href: '/admin/contacts/import-students' },
    ],
};
