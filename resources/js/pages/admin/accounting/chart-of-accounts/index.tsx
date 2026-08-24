import { Head, router } from '@inertiajs/react';
import { BookOpen, Lock, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ChartOfAccountEditSheet } from '@/components/admin/chart-of-account-edit-sheet';
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
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    destroy as accountsDestroy,
    index as accountsIndex,
} from '@/routes/admin/chart-of-accounts';
import type { AccountOption, AccountType, ChartOfAccountRow } from '@/types';

interface Props {
    accounts: ChartOfAccountRow[];
    parentOptions: AccountOption[];
    can: { create: boolean };
}

/**
 * Account types in the order a chart of accounts is conventionally read:
 * balance sheet first (assets, liabilities, equity), then the income
 * statement (income, expenses). The grouping is the point of this page —
 * a flat alphabetical list of 35 codes is a lookup table, whereas the
 * sectioned view is the book an accountant actually reads.
 */
const TYPE_ORDER: readonly AccountType[] = [
    'asset',
    'liability',
    'equity',
    'income',
    'expense',
] as const;

const TYPE_LABELS: Record<AccountType, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    income: 'Income',
    expense: 'Expenses',
};

const CASH_FLOW_LABELS: Record<string, string> = {
    operating: 'Operating',
    investing: 'Investing',
    financing: 'Financing',
    none: '—',
};

function humanizeSubtype(subtype: string | null): string {
    if (subtype === null || subtype === '') {
        return '—';
    }

    return subtype.replaceAll('_', ' ');
}

export default function ChartOfAccountsIndex({
    accounts,
    parentOptions,
    can,
}: Props) {
    // `undefined` while creating; a row while editing. `sheetOpen` is kept
    // separate so the sheet can animate closed without the form blanking
    // mid-transition.
    const [sheetOpen, setSheetOpen] = useState(false);
    const [editing, setEditing] = useState<ChartOfAccountRow | undefined>(
        undefined,
    );

    const openCreate = (): void => {
        setEditing(undefined);
        setSheetOpen(true);
    };

    const openEdit = (row: ChartOfAccountRow): void => {
        setEditing(row);
        setSheetOpen(true);
    };

    const [pendingDelete, setPendingDelete] =
        useState<ChartOfAccountRow | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const handleConfirmDelete = (): void => {
        if (pendingDelete === null) {
            return;
        }

        setIsDeleting(true);

        router.delete(
            accountsDestroy({ chartOfAccount: pendingDelete.id }).url,
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Deleted account ${pendingDelete.code}.`);
                    setPendingDelete(null);
                },
                onError: () => {
                    toast.error(
                        'Could not delete this account. It may have sub-accounts or a tax rate pointing at it — deactivate it instead.',
                    );
                },
                onFinish: () => {
                    setIsDeleting(false);
                },
            },
        );
    };

    const grouped = TYPE_ORDER.map((type) => ({
        type,
        rows: accounts.filter((account) => account.type === type),
    })).filter((group) => group.rows.length > 0);

    return (
        <>
            <Head title="Chart of accounts" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ACCOUNTING"
                    title="Chart of accounts"
                    description="Every account the ledger can post to, grouped as balance sheet then income statement. Locked accounts are used by the system and cannot be deleted."
                    actions={
                        can.create ? (
                            <Button type="button" onClick={openCreate}>
                                <Plus className="mr-1 h-4 w-4" />
                                New account
                            </Button>
                        ) : undefined
                    }
                />

                {accounts.length === 0 ? (
                    <EmptyState
                        icon={BookOpen}
                        title="No accounts yet"
                        description="Add the first account, or run the accounting catalog seeder to load a standard Philippine school chart."
                        action={
                            can.create ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={openCreate}
                                >
                                    <Plus className="mr-1 h-4 w-4" />
                                    New account
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
                                            <TableHead className="w-[7rem] text-xs tracking-wide text-muted-foreground uppercase">
                                                Code
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Name
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Subtype
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Normal balance
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Cash flow
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
                                        {grouped.map((group) => (
                                            <AccountTypeSection
                                                key={group.type}
                                                type={group.type}
                                                rows={group.rows}
                                                onRequestEdit={openEdit}
                                                onRequestDelete={
                                                    setPendingDelete
                                                }
                                            />
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>

            <ChartOfAccountEditSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                account={editing}
                parentOptions={parentOptions}
            />

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
                            Delete this account?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {pendingDelete
                                ? `${pendingDelete.code} — ${pendingDelete.name} will be removed from the chart. If it has sub-accounts, or a tax rate posts to it, the deletion is blocked and you'll be asked to deactivate it instead.`
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

function AccountTypeSection({
    type,
    rows,
    onRequestEdit,
    onRequestDelete,
}: {
    type: AccountType;
    rows: ChartOfAccountRow[];
    onRequestEdit: (row: ChartOfAccountRow) => void;
    onRequestDelete: (row: ChartOfAccountRow) => void;
}) {
    return (
        <>
            <TableRow className="border-b bg-muted/20 hover:bg-muted/20">
                <TableCell colSpan={7} className="py-2">
                    <span className="font-serif text-xs font-semibold tracking-wide text-foreground uppercase">
                        {TYPE_LABELS[type]}
                    </span>
                    <span className="ml-2 text-xs text-muted-foreground tabular-nums">
                        {rows.length}
                    </span>
                </TableCell>
            </TableRow>

            {rows.map((row) => (
                <TableRow
                    key={row.id}
                    className={row.is_active ? undefined : 'opacity-60'}
                >
                    <TableCell className="font-mono text-xs">
                        {row.code}
                    </TableCell>
                    <TableCell className="font-medium">
                        <div className="flex items-center gap-2">
                            {row.name}
                            {row.is_locked ? <SystemAccountBadge /> : null}
                        </div>
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground capitalize">
                        {humanizeSubtype(row.subtype)}
                    </TableCell>
                    <TableCell>
                        <Badge variant="outline" className="capitalize">
                            {row.normal_balance}
                        </Badge>
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">
                        {CASH_FLOW_LABELS[row.cash_flow_category] ??
                            row.cash_flow_category}
                    </TableCell>
                    <TableCell>
                        {row.is_active ? (
                            <Badge className="bg-success/15 text-success hover:bg-success/15">
                                Active
                            </Badge>
                        ) : (
                            <Badge variant="secondary">Inactive</Badge>
                        )}
                    </TableCell>
                    <TableCell className="text-right">
                        <div className="flex justify-end gap-1">
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                aria-label={`Edit account ${row.code}`}
                                onClick={() => onRequestEdit(row)}
                            >
                                <Pencil className="h-4 w-4" />
                            </Button>
                            {row.is_locked ? null : (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    aria-label={`Delete account ${row.code}`}
                                    onClick={() => onRequestDelete(row)}
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            )}
                        </div>
                    </TableCell>
                </TableRow>
            ))}
        </>
    );
}

function SystemAccountBadge() {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="inline-flex items-center gap-1 rounded-sm border border-border px-1.5 py-0.5 text-[0.65rem] tracking-wide text-muted-foreground uppercase">
                    <Lock className="h-3 w-3" aria-hidden="true" />
                    System
                </span>
            </TooltipTrigger>
            <TooltipContent>
                The system posts to this account automatically. You can rename
                it, but not delete or re-code it.
            </TooltipContent>
        </Tooltip>
    );
}

ChartOfAccountsIndex.layout = {
    breadcrumbs: [
        { title: 'Accounting', href: '#' },
        { title: 'Chart of accounts', href: accountsIndex().url },
    ],
};
