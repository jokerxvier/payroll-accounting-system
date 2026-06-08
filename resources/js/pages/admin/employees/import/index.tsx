import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Loader2,
    Upload,
} from 'lucide-react';
import { PageHeader } from '@/components/page-header';
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
import { index as employeesIndex } from '@/routes/employees';

interface ParsedRow {
    row_number: number;
    lms_staff_id: number | null;
    profile_exists: boolean;
    full_name: string | null;
    changes: Record<string, { from: unknown; to: unknown }>;
    errors: string[];
}

interface Props {
    parsed?: ParsedRow[];
    token?: string;
    sourceFilename?: string;
}

function describe(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    return String(value);
}

export default function EmployeesImportIndex({
    parsed,
    token,
    sourceFilename,
}: Props) {
    const upload = useForm({ file: null as File | null });
    const confirm = useForm({});

    const submitUpload = (e: React.FormEvent) => {
        e.preventDefault();
        upload.post('/admin/employees/import/preview', {
            forceFormData: true,
        });
    };

    const submitConfirm = () => {
        if (!token) {
            return;
        }

        confirm.post(`/admin/employees/import/confirm/${token}`);
    };

    const totalRows = parsed?.length ?? 0;
    const errorRows = parsed?.filter((r) => r.errors.length > 0).length ?? 0;
    const noopRows =
        parsed?.filter(
            (r) => r.errors.length === 0 && Object.keys(r.changes).length === 0,
        ).length ?? 0;
    const willChange = totalRows - errorRows - noopRows;

    return (
        <>
            <Head title="Bulk-edit employees (Excel)" />

            <div className="mx-auto max-w-5xl space-y-6 p-4">
                <PageHeader
                    title="Bulk-edit employees"
                    description="Download the template, edit it offline in Excel/Numbers, then upload to preview a per-row diff before applying."
                    actions={
                        <Button asChild variant="outline" size="sm">
                            <Link href={employeesIndex().url}>
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Back to employees
                            </Link>
                        </Button>
                    }
                />

                {/* Step 1 — Download template + Upload */}
                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <FileSpreadsheet className="h-4 w-4" />
                                Step 1 · Download template
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="mb-3 text-xs text-muted-foreground">
                                Each row is one employee with their current
                                payroll-owned fields. Don&apos;t change the{' '}
                                <span className="font-mono">lms_staff_id</span>{' '}
                                column — it&apos;s the join key.
                            </p>
                            <Button asChild variant="outline" size="sm">
                                <a href="/admin/employees/import/template">
                                    <Download className="mr-1 h-4 w-4" />
                                    Download .xlsx
                                </a>
                            </Button>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <Upload className="h-4 w-4" />
                                Step 2 · Upload edited file
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitUpload} className="space-y-3">
                                <div className="space-y-2">
                                    <Label htmlFor="import-file">
                                        Spreadsheet
                                    </Label>
                                    <Input
                                        id="import-file"
                                        type="file"
                                        accept=".xlsx,.xls,.csv"
                                        onChange={(e) =>
                                            upload.setData(
                                                'file',
                                                e.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                    {upload.errors.file ? (
                                        <p className="text-xs text-destructive">
                                            {upload.errors.file}
                                        </p>
                                    ) : null}
                                </div>
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={
                                        upload.processing ||
                                        upload.data.file === null
                                    }
                                >
                                    {upload.processing ? (
                                        <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                                    ) : (
                                        <Upload className="mr-1 h-4 w-4" />
                                    )}
                                    Upload &amp; preview
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>

                {/* Step 3 — Preview + Confirm */}
                {parsed && totalRows > 0 ? (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between gap-2">
                            <div>
                                <CardTitle className="text-sm font-medium">
                                    Step 3 · Preview &amp; confirm
                                </CardTitle>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {sourceFilename ? (
                                        <>
                                            Parsed from{' '}
                                            <span className="font-mono">
                                                {sourceFilename}
                                            </span>
                                            {' · '}
                                        </>
                                    ) : null}
                                    {totalRows} row{totalRows === 1 ? '' : 's'}{' '}
                                    · {willChange} will change · {noopRows}{' '}
                                    no-op · {errorRows} blocked by error
                                    {errorRows === 1 ? '' : 's'}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                disabled={
                                    confirm.processing || willChange === 0
                                }
                                onClick={submitConfirm}
                            >
                                {confirm.processing ? (
                                    <Loader2 className="mr-1 h-4 w-4 animate-spin" />
                                ) : (
                                    <CheckCircle2 className="mr-1 h-4 w-4" />
                                )}
                                Apply {willChange > 0 ? willChange : ''} change
                                {willChange === 1 ? '' : 's'}
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto rounded-md border">
                                <Table className="text-sm">
                                    <TableHeader>
                                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                                            <TableHead className="w-[60px] text-xs tracking-wide text-muted-foreground uppercase">
                                                Row
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Staff
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Status
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Changes / Errors
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {parsed.map((row) => {
                                            const hasErrors =
                                                row.errors.length > 0;
                                            const changeKeys = Object.keys(
                                                row.changes,
                                            );
                                            const hasChanges =
                                                changeKeys.length > 0;

                                            return (
                                                <TableRow
                                                    key={row.row_number}
                                                    className={
                                                        hasErrors
                                                            ? 'bg-destructive/5'
                                                            : ''
                                                    }
                                                >
                                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                                        {row.row_number}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="font-mono text-xs">
                                                            {row.lms_staff_id ??
                                                                '—'}
                                                        </div>
                                                        {row.full_name ? (
                                                            <div className="text-xs text-muted-foreground">
                                                                {row.full_name}
                                                            </div>
                                                        ) : null}
                                                    </TableCell>
                                                    <TableCell>
                                                        {hasErrors ? (
                                                            <Badge variant="destructive">
                                                                <AlertCircle className="mr-1 h-3 w-3" />
                                                                Error
                                                            </Badge>
                                                        ) : hasChanges ? (
                                                            <Badge>
                                                                Will change
                                                            </Badge>
                                                        ) : (
                                                            <Badge variant="outline">
                                                                No-op
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {hasErrors ? (
                                                            <ul className="list-inside list-disc space-y-0.5 text-xs text-destructive">
                                                                {row.errors.map(
                                                                    (
                                                                        err,
                                                                        i,
                                                                    ) => (
                                                                        <li
                                                                            key={
                                                                                i
                                                                            }
                                                                        >
                                                                            {
                                                                                err
                                                                            }
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                        ) : hasChanges ? (
                                                            <ul className="space-y-0.5 text-xs">
                                                                {changeKeys.map(
                                                                    (k) => (
                                                                        <li
                                                                            key={
                                                                                k
                                                                            }
                                                                        >
                                                                            <span className="font-mono">
                                                                                {
                                                                                    k
                                                                                }
                                                                            </span>
                                                                            :{' '}
                                                                            <span className="text-muted-foreground line-through">
                                                                                {describe(
                                                                                    row
                                                                                        .changes[
                                                                                        k
                                                                                    ]
                                                                                        .from,
                                                                                )}
                                                                            </span>{' '}
                                                                            →{' '}
                                                                            <span className="font-medium">
                                                                                {describe(
                                                                                    row
                                                                                        .changes[
                                                                                        k
                                                                                    ]
                                                                                        .to,
                                                                                )}
                                                                            </span>
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                        ) : (
                                                            <span className="text-xs text-muted-foreground">
                                                                —
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                            {errorRows > 0 ? (
                                <p className="mt-3 text-xs text-muted-foreground">
                                    Rows with errors are skipped on confirm —
                                    the rest are applied in a single
                                    transaction. Re-upload after fixing the
                                    spreadsheet to retry the blocked rows.
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : null}
            </div>
        </>
    );
}

EmployeesImportIndex.layout = {
    breadcrumbs: [
        { title: 'Employees', href: '/employees' },
        { title: 'Bulk import', href: '/admin/employees/import' },
    ],
};
