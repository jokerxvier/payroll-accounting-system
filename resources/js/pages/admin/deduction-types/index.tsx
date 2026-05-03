import { Head, Link, router } from '@inertiajs/react';
import { MinusCircle, Pencil, Plus, Trash2 } from 'lucide-react';
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
    create as deductionTypesCreate,
    destroy as deductionTypesDestroy,
    edit as deductionTypesEdit,
    index as deductionTypesIndex,
} from '@/routes/admin/deduction-types';
import type {
    DeductionCalcMethod,
    DeductionSource,
    DeductionTypeRow,
} from '@/types';

interface Props {
    deductionTypes: DeductionTypeRow[];
}

const CALC_METHOD_LABELS: Record<DeductionCalcMethod, string> = {
    fixed: 'Fixed',
    percent: 'Percent',
};

const SOURCE_LABELS: Record<DeductionSource, string> = {
    employee: 'Employee',
    employer: 'Employer',
    both: 'Both',
};

function truncate(text: string | null, max = 80): string {
    if (text === null || text === '') {
        return '—';
    }

    if (text.length <= max) {
        return text;
    }

    return `${text.slice(0, max - 1)}…`;
}

export default function DeductionTypesIndex({ deductionTypes }: Props) {
    const [pendingDelete, setPendingDelete] = useState<DeductionTypeRow | null>(
        null,
    );
    const [isDeleting, setIsDeleting] = useState(false);

    const handleConfirmDelete = (): void => {
        if (pendingDelete === null) {
            return;
        }

        setIsDeleting(true);

        router.delete(
            deductionTypesDestroy({ deductionType: pendingDelete.id }).url,
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Deleted ${pendingDelete.code}.`);
                    setPendingDelete(null);
                },
                onError: () => {
                    toast.error(
                        'Could not delete this deduction type. It may have active subscriptions — toggle Active off instead.',
                    );
                },
                onFinish: () => {
                    setIsDeleting(false);
                },
            },
        );
    };

    return (
        <>
            <Head title="Deduction Types" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ADMIN"
                    title="Deduction types"
                    description="Catalog of payroll deduction types managed by super-admins. Subscriptions per employee live on the employee's profile."
                    actions={
                        <Button asChild>
                            <Link href={deductionTypesCreate().url}>
                                <Plus className="mr-1 h-4 w-4" />
                                New deduction type
                            </Link>
                        </Button>
                    }
                />

                {deductionTypes.length === 0 ? (
                    <EmptyState
                        icon={MinusCircle}
                        title="No deduction types yet"
                        description="Add the first catalog row to make it selectable when creating an employee subscription."
                        action={
                            <Button asChild size="sm">
                                <Link href={deductionTypesCreate().url}>
                                    <Plus className="mr-1 h-4 w-4" />
                                    New deduction type
                                </Link>
                            </Button>
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
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Calc method
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Source
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Taxable
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Status
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Notes
                                            </TableHead>
                                            <TableHead className="sr-only text-right">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {deductionTypes.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {row.code}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {row.name}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="secondary">
                                                        {
                                                            CALC_METHOD_LABELS[
                                                                row.calc_method
                                                            ]
                                                        }
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {
                                                            SOURCE_LABELS[
                                                                row.source
                                                            ]
                                                        }
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {row.is_taxable ? (
                                                        <Badge variant="outline">
                                                            Taxable
                                                        </Badge>
                                                    ) : (
                                                        <Badge className="bg-success/15 text-success hover:bg-success/15">
                                                            Non-taxable
                                                        </Badge>
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
                                                <TableCell className="max-w-[20rem] text-xs text-muted-foreground">
                                                    <span className="line-clamp-2">
                                                        {truncate(row.notes)}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            asChild
                                                            size="sm"
                                                            variant="ghost"
                                                            aria-label={`Edit ${row.code}`}
                                                        >
                                                            <Link
                                                                href={
                                                                    deductionTypesEdit(
                                                                        {
                                                                            deductionType:
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
                                                            aria-label={`Delete ${row.code}`}
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
                            Delete this deduction type?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {pendingDelete
                                ? `'${pendingDelete.code} — ${pendingDelete.name}' will be removed from the catalog. If any employee is currently subscribed to it, the deletion will be blocked and you'll be asked to deactivate it instead.`
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

DeductionTypesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        { title: 'Deduction types', href: deductionTypesIndex().url },
    ],
};
