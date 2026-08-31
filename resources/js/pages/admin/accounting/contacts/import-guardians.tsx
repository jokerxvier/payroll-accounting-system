import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    Check,
    RefreshCw,
    UserPlus,
    Users,
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
 * Importing parents from the school records.
 *
 * The preview is the whole point: it writes nothing, so someone can read a
 * list of every family and see exactly what would change before any of it
 * lands. Each row says which action it will take and why, and a row that
 * cannot be resolved says so rather than being quietly skipped.
 */

interface ImportedStudent {
    lms_student_id: number;
    name: string;
}

interface GuardianRow {
    lms_parent_id: number;
    name: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    relationship: string | null;
    students: ImportedStudent[];
    existing_contact_id: number | null;
    existing_contact_name: string | null;
    action: 'create' | 'link' | 'unchanged';
    errors: string[];
}

interface Props {
    parsed?: GuardianRow[] | null;
    token?: string | null;
    summary?: {
        create: number;
        link: number;
        unchanged: number;
        errors: number;
        students: number;
    } | null;
}

const ACTION_LABELS: Record<GuardianRow['action'], string> = {
    create: 'New contact',
    link: 'Link students',
    unchanged: 'Already imported',
};

export default function ImportGuardians({ parsed, token, summary }: Props) {
    const scan = useForm({});
    const confirm = useForm({});

    const runScan = (e: FormEvent) => {
        e.preventDefault();
        scan.post('/admin/contacts/import-guardians/preview');
    };

    const runConfirm = (e: FormEvent) => {
        e.preventDefault();

        if (token) {
            confirm.post(`/admin/contacts/import-guardians/confirm/${token}`);
        }
    };

    const applicable = (summary?.create ?? 0) + (summary?.link ?? 0);

    return (
        <>
            <Head title="Import guardians" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Import guardians"
                    description="Bring the school's parents and guardians in as billing contacts, linked to the students they pay for. One contact per payer, however many children they have."
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
                                        <UserPlus className="size-4 text-muted-foreground" />
                                    }
                                    label="New contacts"
                                    value={summary.create}
                                />
                                <Stat
                                    icon={
                                        <Users className="size-4 text-muted-foreground" />
                                    }
                                    label="Students linked"
                                    value={summary.students}
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
                                        {summary.errors} record
                                        {summary.errors === 1 ? '' : 's'} need
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
                                    No active students with a parent record were
                                    found in the school system.
                                </p>
                            ) : (
                                <div className="rounded-md border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Parent</TableHead>
                                                <TableHead>Contact</TableHead>
                                                <TableHead>Students</TableHead>
                                                <TableHead className="w-40">
                                                    Action
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {parsed.map((row) => (
                                                <TableRow
                                                    key={row.lms_parent_id}
                                                    className={cn(
                                                        row.errors.length > 0 &&
                                                            'bg-destructive/5',
                                                    )}
                                                >
                                                    <TableCell className="align-top">
                                                        <div className="font-medium">
                                                            {row.name ?? '—'}
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
                                                        {row.email ??
                                                            row.phone ??
                                                            '—'}
                                                    </TableCell>
                                                    <TableCell className="align-top">
                                                        <ul className="space-y-0.5 text-sm">
                                                            {row.students.map(
                                                                (s) => (
                                                                    <li
                                                                        key={
                                                                            s.lms_student_id
                                                                        }
                                                                    >
                                                                        {s.name}
                                                                    </li>
                                                                ),
                                                            )}
                                                        </ul>
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
                                        : `Import ${applicable} contact${applicable === 1 ? '' : 's'}`}
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

ImportGuardians.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/journal-entries' },
        { title: 'Contacts', href: '/admin/contacts' },
        { title: 'Import guardians', href: '/admin/contacts/import-guardians' },
    ],
};
