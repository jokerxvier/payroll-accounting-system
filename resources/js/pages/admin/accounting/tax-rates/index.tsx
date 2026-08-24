import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Percent, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
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
import {
    create as taxRatesCreate,
    destroy as taxRatesDestroy,
    edit as taxRatesEdit,
    index as taxRatesIndex,
} from '@/routes/admin/tax-rates';
import type { TaxRateRow, TaxRateType } from '@/types';

interface Props {
    taxRates: TaxRateRow[];
    can: { create: boolean };
}

const TYPE_LABELS: Record<TaxRateType, string> = {
    vat_sales: 'Output VAT',
    vat_purchase: 'Input VAT',
    exempt: 'Exempt',
    zero_rated: 'Zero-rated',
};

/** 1200 → "12%", 1250 → "12.5%". Mirrors TaxRate::ratePercentLabel(). */
function ratePercentLabel(bps: number): string {
    return `${String(bps / 100)}%`;
}

export default function TaxRatesIndex({ taxRates, can }: Props) {
    const [pendingDelete, setPendingDelete] = useState<TaxRateRow | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const handleConfirmDelete = (): void => {
        if (pendingDelete === null) {
            return;
        }

        setIsDeleting(true);

        router.delete(taxRatesDestroy({ taxRate: pendingDelete.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Deleted tax rate ${pendingDelete.code}.`);
                setPendingDelete(null);
            },
            onError: () => {
                toast.error('Could not delete this tax rate.');
            },
            onFinish: () => {
                setIsDeleting(false);
            },
        });
    };

    return (
        <>
            <Head title="Tax rates" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Tax rates"
                    description="Rates applied to invoice and bill lines. Exempt and zero-rated both charge nothing, but BIR requires them reported as separate subtotals."
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link href={taxRatesCreate().url}>
                                    <Plus className="mr-1 h-4 w-4" />
                                    New tax rate
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {taxRates.length === 0 ? (
                    <EmptyState
                        icon={Percent}
                        title="No tax rates yet"
                        description="Add the rates this school charges, or run the accounting catalog seeder to load the standard Philippine set."
                        action={
                            can.create ? (
                                <Button asChild size="sm">
                                    <Link href={taxRatesCreate().url}>
                                        <Plus className="mr-1 h-4 w-4" />
                                        New tax rate
                                    </Link>
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
                                                Code
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Name
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Rate
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Treatment
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Posts to
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
                                        {taxRates.map((row) => (
                                            <TableRow
                                                key={row.id}
                                                className={
                                                    row.is_active
                                                        ? undefined
                                                        : 'opacity-60'
                                                }
                                            >
                                                <TableCell className="font-mono text-xs">
                                                    {row.code}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {row.name}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {ratePercentLabel(
                                                        row.rate_bps,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {TYPE_LABELS[row.type]}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground">
                                                    {row.account === null ? (
                                                        '—'
                                                    ) : (
                                                        <span>
                                                            <span className="font-mono">
                                                                {
                                                                    row.account
                                                                        .code
                                                                }
                                                            </span>{' '}
                                                            {row.account.name}
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {row.is_active ? (
                                                        <Badge className="bg-success/15 text-success hover:bg-success/15">
                                                            Active
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="secondary">
                                                            Inactive
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            asChild
                                                            size="sm"
                                                            variant="ghost"
                                                            aria-label={`Edit tax rate ${row.code}`}
                                                        >
                                                            <Link
                                                                href={
                                                                    taxRatesEdit(
                                                                        {
                                                                            taxRate:
                                                                                row.id,
                                                                        },
                                                                    ).url
                                                                }
                                                            >
                                                                <Pencil className="h-4 w-4" />
                                                            </Link>
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                            aria-label={`Delete tax rate ${row.code}`}
                                                            onClick={() =>
                                                                setPendingDelete(
                                                                    row,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </div>
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

            <AlertDialog
                open={pendingDelete !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingDelete(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete this tax rate?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {pendingDelete
                                ? `'${pendingDelete.code} — ${pendingDelete.name}' will be removed. Deactivate it instead if any document still needs to display it.`
                                : ''}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            onClick={(event) => {
                                event.preventDefault();
                                handleConfirmDelete();
                            }}
                            disabled={isDeleting}
                        >
                            {isDeleting ? 'Deleting…' : 'Delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

TaxRatesIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Tax rates', href: taxRatesIndex().url },
    ],
};
