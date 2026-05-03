import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, PlusCircle, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/empty-state';
import { Money } from '@/components/money';
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
    create as allowancesCreate,
    destroy as allowancesDestroy,
    edit as allowancesEdit,
    index as allowancesIndex,
} from '@/routes/admin/allowances';
import type { AllowanceRow } from '@/types';

interface Props {
    allowances: AllowanceRow[];
}

function truncate(text: string | null, max = 80): string {
    if (text === null || text === '') {
        return '—';
    }

    if (text.length <= max) {
        return text;
    }

    return `${text.slice(0, max - 1)}…`;
}

export default function AllowancesIndex({ allowances }: Props) {
    const [pendingDelete, setPendingDelete] = useState<AllowanceRow | null>(
        null,
    );
    const [isDeleting, setIsDeleting] = useState(false);

    const handleConfirmDelete = (): void => {
        if (pendingDelete === null) {
            return;
        }

        setIsDeleting(true);

        router.delete(allowancesDestroy({ allowance: pendingDelete.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Deleted ${pendingDelete.code}.`);
                setPendingDelete(null);
            },
            onError: () => {
                toast.error(
                    'Could not delete this allowance. It may have active subscriptions — toggle Active off instead.',
                );
            },
            onFinish: () => {
                setIsDeleting(false);
            },
        });
    };

    return (
        <>
            <Head title="Allowances" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ADMIN"
                    title="Allowances"
                    description="Catalog of allowance types managed by super-admins. Subscriptions per employee live on the employee's profile."
                    actions={
                        <Button asChild>
                            <Link href={allowancesCreate().url}>
                                <Plus className="mr-1 h-4 w-4" />
                                New allowance
                            </Link>
                        </Button>
                    }
                />

                {allowances.length === 0 ? (
                    <EmptyState
                        icon={PlusCircle}
                        title="No allowances yet"
                        description="Add the first catalog row to make it selectable when creating an employee subscription."
                        action={
                            <Button asChild size="sm">
                                <Link href={allowancesCreate().url}>
                                    <Plus className="mr-1 h-4 w-4" />
                                    New allowance
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
                                                Taxable
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                De-minimis
                                            </TableHead>
                                            <TableHead className="text-right text-xs tracking-wide text-muted-foreground uppercase">
                                                Default amount
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
                                        {allowances.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {row.code}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {row.name}
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
                                                    <DeminimisBadge row={row} />
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {row.default_amount_centavos ===
                                                    null ? (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    ) : (
                                                        <Money
                                                            amount={
                                                                row.default_amount_centavos /
                                                                100
                                                            }
                                                        />
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
                                                                    allowancesEdit(
                                                                        {
                                                                            allowance:
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
                            Delete this allowance?
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

function DeminimisBadge({ row }: { row: AllowanceRow }) {
    if (!row.is_de_minimis) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <div className="flex items-center gap-2">
            <Badge variant="outline">De-minimis</Badge>
            {row.de_minimis_cap_centavos === null ? (
                <span className="text-xs text-muted-foreground">
                    no cap set
                </span>
            ) : (
                <span className="text-xs text-muted-foreground tabular-nums">
                    cap{' '}
                    <Money
                        amount={row.de_minimis_cap_centavos / 100}
                        className="text-xs"
                    />
                </span>
            )}
        </div>
    );
}

AllowancesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        { title: 'Allowances', href: allowancesIndex().url },
    ],
};
