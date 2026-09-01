import { useForm } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    Download,
    Loader2,
    Minus,
    PencilLine,
    Plus,
    Upload,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type {
    ChartImportPreview,
    ChartImportRow,
} from '@/types/chart-of-account-import';

const PREVIEW_URL = '/admin/chart-of-accounts/import/preview';
const TEMPLATE_URL = '/admin/chart-of-accounts/import/template';
const EXPORT_URL = '/admin/chart-of-accounts/export';

/** Column names as the screen says them, where the sheet's differ. */
const FIELD_LABELS: Record<string, string> = {
    name: 'Name',
    type: 'Type',
    subtype: 'Subtype',
    parent_id: 'Parent',
    cash_flow_category: 'Cash flow',
    is_cash_equivalent: 'Cash equivalent',
    is_active: 'Active',
    description: 'Description',
    // Never taken from the sheet — it follows the type. Shown when it moves
    // so a type change does not look like it did one thing when it did two.
    normal_balance: 'Normal balance',
};

/**
 * Import the chart from a spreadsheet, without leaving the chart.
 *
 * A dialog rather than a page because the thing being changed is on screen
 * behind it: an accountant checking a diff wants the chart it applies to
 * within glancing distance, and a separate page trades that for nothing.
 *
 * The preview still costs a server round trip — the file has to be parsed
 * against the stored chart to say what will move. So the flow is upload →
 * redirect back → this reopens with `preview` populated from the session.
 * `open` is controlled by the page for that reason.
 */
export function ChartOfAccountImportDialog({
    open,
    onOpenChange,
    preview,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    preview?: ChartImportPreview | null;
}) {
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

        if (preview?.token) {
            confirm.post(
                `/admin/chart-of-accounts/import/confirm/${preview.token}`,
            );
        }
    };

    /*
     * The keys the CONTROLLER refuses under, which the form's own (empty)
     * data cannot tell TypeScript about. Read off this form instance, not the
     * page's shared errors: the upload form also refuses under `file`.
     */
    const confirmErrors = confirm.errors as Partial<
        Record<'file' | 'token', string>
    >;
    const confirmError = confirmErrors.file ?? confirmErrors.token ?? null;

    const summary = preview?.summary;
    const hasRowErrors = (summary?.error_count ?? 0) > 0;
    const willChange =
        (summary?.create_count ?? 0) + (summary?.update_count ?? 0) > 0;
    const canConfirm = !!preview?.token && !hasRowErrors && willChange;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[85vh] flex-col sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Import chart of accounts</DialogTitle>
                    <DialogDescription>
                        Rows are matched on code — an existing code updates that
                        account, a new one creates it. Changing a code does not
                        renumber an account; it creates a second one.
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto pr-1">
                    {confirmError && (
                        <Alert variant="destructive">
                            <AlertCircle className="size-4" />
                            <AlertTitle>Nothing was imported</AlertTitle>
                            <AlertDescription>{confirmError}</AlertDescription>
                        </Alert>
                    )}

                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href={EXPORT_URL}>
                                <Download className="size-4" />
                                Export current chart
                            </a>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={TEMPLATE_URL}>
                                <Download className="size-4" />
                                Download template
                            </a>
                        </Button>
                    </div>

                    <form onSubmit={submitUpload} className="space-y-2">
                        <Label htmlFor="chart-import-file">Worksheet</Label>
                        <div className="flex gap-2">
                            <Input
                                id="chart-import-file"
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
                            <Button
                                type="submit"
                                variant="secondary"
                                disabled={
                                    upload.processing || !upload.data.file
                                }
                            >
                                {upload.processing ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Upload className="size-4" />
                                )}
                                Check
                            </Button>
                        </div>
                        {upload.errors.file && (
                            <p className="text-sm text-destructive">
                                {upload.errors.file}
                            </p>
                        )}
                    </form>

                    {preview && summary && (
                        <div className="space-y-3 border-t pt-4">
                            <div className="flex flex-wrap items-center gap-2">
                                {preview.sourceFilename && (
                                    <span className="text-sm text-muted-foreground">
                                        {preview.sourceFilename}
                                    </span>
                                )}
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
                                        it again.
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
                                        Every row matches the chart as it
                                        stands.
                                    </AlertDescription>
                                </Alert>
                            )}

                            <ul className="divide-y rounded-md border">
                                {preview.parsed.map((row) => (
                                    <PreviewRow
                                        key={row.row_number}
                                        row={row}
                                    />
                                ))}
                            </ul>
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
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
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A list row rather than a table row.
 *
 * A dialog is narrower than a page, and a diff that names fields needs to
 * wrap. Twelve columns squeezed into a modal would scroll sideways, which is
 * the one thing a reader checking a change should not have to do.
 */
function PreviewRow({ row }: { row: ChartImportRow }) {
    const failed = row.errors.length > 0;

    return (
        <li className={cn('p-3 text-sm', failed && 'bg-destructive/5')}>
            <div className="flex flex-wrap items-baseline gap-x-2">
                <span className="font-mono text-xs text-muted-foreground">
                    {row.code ?? `row ${row.row_number}`}
                </span>
                <span className="font-medium">{row.name ?? '—'}</span>
                <RowBadge row={row} />
            </div>

            {failed ? (
                <div className="mt-1 space-y-0.5">
                    {row.errors.map((error) => (
                        <p key={error} className="text-destructive">
                            {error}
                        </p>
                    ))}
                </div>
            ) : (
                row.action === 'update' && <Changes row={row} />
            )}
        </li>
    );
}

function RowBadge({ row }: { row: ChartImportRow }) {
    if (row.errors.length > 0) {
        return null;
    }

    if (row.action === 'create') {
        return (
            <span className="inline-flex items-center gap-1 text-xs text-success">
                <Plus className="size-3" />
                New
            </span>
        );
    }

    if (row.action === 'unchanged') {
        return (
            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                <Minus className="size-3" />
                No change
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1 text-xs font-medium">
            <PencilLine className="size-3" />
            Updated
        </span>
    );
}

/**
 * Which fields move, and what they move from.
 *
 * Naming them is the point of the preview. "12 accounts will be updated" is
 * not something anyone can check against a chart they are responsible for.
 */
function Changes({ row }: { row: ChartImportRow }) {
    return (
        <dl className="mt-1 space-y-0.5 text-xs">
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
