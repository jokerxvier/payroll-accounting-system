import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    CheckCircle2,
    Download,
    Loader2,
    Minus,
    PencilLine,
    Plus,
    Upload,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type {
    ContactImportPageProps,
    ContactImportRow,
} from '@/types/contact-import';

const PREVIEW_URL = '/admin/contacts/import/preview';
const TEMPLATE_URL = '/admin/contacts/import/template';
const EXPORT_URL = '/admin/contacts/export';

/** How a column reads on screen, where the sheet's name is not the clearest. */
const FIELD_LABELS: Record<string, string> = {
    name: 'Name',
    is_customer: 'Customer',
    is_supplier: 'Supplier',
    is_active: 'Active',
    tin: 'TIN',
    email: 'Email',
    phone: 'Phone',
    address: 'Address',
    notes: 'Notes',
    receivable_account_id: 'Receivable account',
    payable_account_id: 'Payable account',
};

export default function ContactImportPage({
    parsed,
    token,
    sourceFilename,
    summary,
}: ContactImportPageProps) {
    const upload = useForm({ file: null as File | null });
    const confirm = useForm({});

    const submitUpload = (e: FormEvent) => {
        e.preventDefault();
        upload.post(PREVIEW_URL, {
            forceFormData: true,
            onSuccess: () => confirm.clearErrors(),
        });
    };

    const submitConfirm = (e: FormEvent) => {
        e.preventDefault();

        if (token) {
            confirm.post(`/admin/contacts/import/confirm/${token}`);
        }
    };

    /*
     * The keys the CONTROLLER refuses under, which the form's own (empty) data
     * cannot tell TypeScript about. Read off the form instance and not the
     * page's shared `errors` prop: the upload form also refuses under `file`.
     */
    const confirmErrors = confirm.errors as Partial<
        Record<'file' | 'token', string>
    >;
    const confirmError = confirmErrors.file ?? confirmErrors.token ?? null;

    const hasRowErrors = (summary?.error_count ?? 0) > 0;
    const willChange =
        (summary?.create_count ?? 0) + (summary?.update_count ?? 0) > 0;
    const canConfirm = !!token && !hasRowErrors && willChange;

    return (
        <>
            <Head title="Import contacts" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Import contacts"
                    description="Take the register out to a spreadsheet, correct it, and put it back. Rows are matched on code — an existing code updates that contact, a new one creates it."
                    actions={
                        <Button asChild variant="outline">
                            <Link href="/admin/contacts">
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to contacts
                            </Link>
                        </Button>
                    }
                />

                {confirmError && (
                    <Alert variant="destructive">
                        <AlertCircle className="size-4" />
                        <AlertTitle>Nothing was imported</AlertTitle>
                        <AlertDescription>{confirmError}</AlertDescription>
                    </Alert>
                )}

                {/* Step 1 — get a file to work from */}
                <Card>
                    <CardHeader>
                        <CardTitle>1. Start from a file</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p className="text-sm text-muted-foreground">
                            Export the register to correct what is already
                            there, or take the empty template to add contacts in
                            bulk. Both files have the same columns, so either
                            can be uploaded back.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <a href={EXPORT_URL}>
                                    <Download className="size-4" />
                                    Export contacts
                                </a>
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={TEMPLATE_URL}>
                                    <Download className="size-4" />
                                    Download template
                                </a>
                            </Button>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Leave the <code className="font-mono">code</code>{' '}
                            column alone. Changing a code does not rename a
                            contact — it creates a second one.
                        </p>
                    </CardContent>
                </Card>

                {/* Step 2 — upload */}
                <Card>
                    <CardHeader>
                        <CardTitle>2. Upload it back</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={submitUpload}
                            className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end"
                        >
                            <div className="space-y-2">
                                <Label htmlFor="file">Worksheet</Label>
                                <Input
                                    id="file"
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    aria-invalid={!!upload.errors.file}
                                    onChange={(e) =>
                                        upload.setData(
                                            'file',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                                {upload.errors.file && (
                                    <p className="text-sm text-destructive">
                                        {upload.errors.file}
                                    </p>
                                )}
                            </div>
                            <Button
                                type="submit"
                                disabled={
                                    upload.processing || !upload.data.file
                                }
                            >
                                {upload.processing ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Upload className="size-4" />
                                )}
                                Check the changes
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {/* Step 3 — what will happen */}
                {parsed && summary && (
                    <Card>
                        <CardHeader className="flex-row items-center justify-between gap-4 space-y-0">
                            <CardTitle>
                                3. Review and apply
                                {sourceFilename && (
                                    <span className="ml-2 font-normal text-muted-foreground">
                                        {sourceFilename}
                                    </span>
                                )}
                            </CardTitle>
                            <div className="flex gap-2">
                                <Badge variant="outline">
                                    {summary.create_count} new
                                </Badge>
                                <Badge variant="outline">
                                    {summary.update_count} changed
                                </Badge>
                                <Badge variant="outline">
                                    {summary.unchanged_count} untouched
                                </Badge>
                            </div>
                        </CardHeader>

                        <CardContent className="space-y-6">
                            {hasRowErrors && (
                                <Alert variant="destructive">
                                    <AlertCircle className="size-4" />
                                    <AlertTitle>
                                        {summary.error_count} problem
                                        {summary.error_count === 1
                                            ? ''
                                            : 's'}{' '}
                                        to fix
                                    </AlertTitle>
                                    <AlertDescription>
                                        Nothing is applied while any row is
                                        wrong. Correct the worksheet and upload
                                        it again — re-uploading is safe, because
                                        rows are matched on code.
                                    </AlertDescription>
                                </Alert>
                            )}

                            {!hasRowErrors && !willChange && (
                                <Alert>
                                    <CheckCircle2 className="size-4" />
                                    <AlertTitle>
                                        This file changes nothing
                                    </AlertTitle>
                                    <AlertDescription>
                                        Every row matches what is already
                                        stored, so there is nothing to apply.
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-14">
                                                Row
                                            </TableHead>
                                            <TableHead className="w-28">
                                                Code
                                            </TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead>What happens</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {parsed.map((row) => (
                                            <PreviewRow
                                                key={row.row_number}
                                                row={row}
                                            />
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            <form onSubmit={submitConfirm}>
                                <Button
                                    type="submit"
                                    disabled={!canConfirm || confirm.processing}
                                >
                                    {confirm.processing ? (
                                        <Loader2 className="size-4 animate-spin" />
                                    ) : (
                                        <CheckCircle2 className="size-4" />
                                    )}
                                    Apply these changes
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

function PreviewRow({ row }: { row: ContactImportRow }) {
    const failed = row.errors.length > 0;

    return (
        <TableRow className={cn(failed && 'bg-destructive/5')}>
            <TableCell className="text-muted-foreground tabular-nums">
                {row.row_number}
            </TableCell>
            <TableCell className="font-mono text-xs">
                {row.code ?? '—'}
            </TableCell>
            <TableCell>{row.name ?? '—'}</TableCell>
            <TableCell>
                {failed ? (
                    <div className="space-y-1">
                        {row.errors.map((error) => (
                            <p key={error} className="text-sm text-destructive">
                                {error}
                            </p>
                        ))}
                    </div>
                ) : (
                    <RowOutcome row={row} />
                )}
            </TableCell>
        </TableRow>
    );
}

/**
 * What this row does, and for an update exactly which fields move.
 *
 * Naming the fields is the point of the preview. "12 contacts will be updated"
 * is not something anyone can check; "Email: family@old → family@new" is.
 */
function RowOutcome({ row }: { row: ContactImportRow }) {
    if (row.action === 'create') {
        return (
            <span className="inline-flex items-center gap-1.5 text-sm text-success">
                <Plus className="size-3.5" />
                New contact
            </span>
        );
    }

    if (row.action === 'unchanged') {
        return (
            <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                <Minus className="size-3.5" />
                No change
            </span>
        );
    }

    return (
        <div className="space-y-1">
            <span className="inline-flex items-center gap-1.5 text-sm font-medium">
                <PencilLine className="size-3.5" />
                Updated
            </span>
            <dl className="space-y-0.5 text-sm">
                {Object.entries(row.changes).map(([field, change]) => (
                    <div key={field} className="flex flex-wrap gap-x-2">
                        <dt className="text-muted-foreground">
                            {FIELD_LABELS[field] ?? field}:
                        </dt>
                        <dd>
                            <span className="text-muted-foreground line-through">
                                {display(change.from)}
                            </span>{' '}
                            <span className="font-medium">
                                {display(change.to)}
                            </span>
                        </dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

/** A stored value as a reader recognises it, not as JSON prints it. */
function display(value: string | number | boolean | null): string {
    if (value === null || value === '') {
        return '(empty)';
    }

    if (typeof value === 'boolean') {
        return value ? 'yes' : 'no';
    }

    return String(value);
}

ContactImportPage.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '/admin/journal-entries' },
        { title: 'Contacts', href: '/admin/contacts' },
        { title: 'Import', href: '/admin/contacts/import' },
    ],
};
