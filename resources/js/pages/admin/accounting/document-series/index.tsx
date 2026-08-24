import { Head } from '@inertiajs/react';
import { Hash, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { DocumentSeriesEditSheet } from '@/components/admin/document-series-edit-sheet';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as seriesIndex } from '@/routes/admin/document-series';
import type { DocumentSeriesIndexProps, DocumentSeriesRow } from '@/types';

const TYPE_LABELS: Record<string, string> = {
    sales_invoice: 'Sales invoice',
    official_receipt: 'Official receipt',
    credit_note: 'Credit note',
    bill: 'Bill',
};

export default function DocumentSeriesIndex({
    series,
    documentTypes,
    can,
}: DocumentSeriesIndexProps) {
    const [editing, setEditing] = useState<DocumentSeriesRow | undefined>();
    const [sheetOpen, setSheetOpen] = useState(false);

    const openCreate = (): void => {
        setEditing(undefined);
        setSheetOpen(true);
    };

    const openEdit = (row: DocumentSeriesRow): void => {
        setEditing(row);
        setSheetOpen(true);
    };

    return (
        <>
            <Head title="Document numbering" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Document numbering"
                    description="Where invoice and receipt numbers come from. Numbers are issued in sequence with no gaps — a document that fails to save gives its number back rather than burning it."
                    actions={
                        can.create ? (
                            <Button onClick={openCreate}>
                                <Plus className="mr-1 h-4 w-4" />
                                New series
                            </Button>
                        ) : undefined
                    }
                />

                {series.length === 0 ? (
                    <EmptyState
                        icon={Hash}
                        title="No numbering series yet"
                        description="Set up a series before approving the first invoice. The BIR permit details can be added later — a series without them still issues numbers."
                        action={
                            can.create ? (
                                <Button size="sm" onClick={openCreate}>
                                    <Plus className="mr-1 h-4 w-4" />
                                    New series
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <Table className="text-sm">
                                    <TableHeader>
                                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Document
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Name
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Next number
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Authority To Print
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Remaining
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Status
                                            </TableHead>
                                            <TableHead className="sr-only text-right">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {series.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell>
                                                    {TYPE_LABELS[
                                                        row.document_type
                                                    ] ?? row.document_type}
                                                </TableCell>
                                                <TableCell>
                                                    {row.label}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {row.next_formatted}
                                                </TableCell>
                                                <TableCell className="text-xs">
                                                    {row.has_authority_to_print ? (
                                                        <span className="font-mono">
                                                            {row.atp_number}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            Not registered
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {row.remaining_in_range ===
                                                    null ? (
                                                        <span className="text-muted-foreground">
                                                            Unlimited
                                                        </span>
                                                    ) : (
                                                        row.remaining_in_range
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <SeriesStatusBadge
                                                        row={row}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {row.can.update ? (
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            variant="ghost"
                                                            className="h-7 w-7"
                                                            aria-label={`Edit ${row.label}`}
                                                            onClick={() =>
                                                                openEdit(row)
                                                            }
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Button>
                                                    ) : null}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>

            <DocumentSeriesEditSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                series={editing}
                documentTypes={documentTypes}
            />
        </>
    );
}

/**
 * A series running low on authorised numbers is the one thing on this page
 * that needs acting on before it bites — approval fails outright at the end
 * of the range, so the warning has to arrive before then.
 */
function SeriesStatusBadge({ row }: { row: DocumentSeriesRow }) {
    if (!row.is_active) {
        return <Badge variant="secondary">Inactive</Badge>;
    }

    if (row.remaining_in_range !== null && row.remaining_in_range === 0) {
        return <Badge variant="destructive">Range exhausted</Badge>;
    }

    if (row.remaining_in_range !== null && row.remaining_in_range <= 50) {
        return <Badge variant="outline">Running low</Badge>;
    }

    return (
        <Badge className="bg-success/15 text-success hover:bg-success/15">
            Active
        </Badge>
    );
}

DocumentSeriesIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Document numbering', href: seriesIndex().url },
    ],
};
