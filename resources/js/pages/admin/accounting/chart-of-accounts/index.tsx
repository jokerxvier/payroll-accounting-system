import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    ChevronRight,
    Download,
    Lock,
    Pencil,
    Plus,
    Trash2,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ChartOfAccountEditSheet } from '@/components/admin/chart-of-account-edit-sheet';
import { ChartOfAccountImportDialog } from '@/components/admin/chart-of-account-import-dialog';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    destroy as accountsDestroy,
    index as accountsIndex,
} from '@/routes/admin/chart-of-accounts';
import type { AccountOption, AccountType, ChartOfAccountRow } from '@/types';
import type { ChartImportPreview } from '@/types/chart-of-account-import';

interface Props {
    accounts: ChartOfAccountRow[];
    parentOptions: AccountOption[];
    can: { create: boolean };
    /** Present only after an upload — its presence reopens the dialog. */
    import?: ChartImportPreview | null;
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
    income: 'Revenue',
    expense: 'Expenses',
};

/**
 * `all` keeps the whole chart on one page — the sectioned view this page was
 * built around, and still the default. The per-type tabs are a focus tool on
 * top of it, not a replacement: an accountant reconciling expenses wants the
 * expense block alone, but reading the chart as a book is what the page is
 * for.
 */
type TabKey = 'all' | AccountType;

const TAB_KEYS: readonly TabKey[] = ['all', ...TYPE_ORDER] as const;

function isTabKey(value: string): value is TabKey {
    return (TAB_KEYS as readonly string[]).includes(value);
}

/**
 * The active tab is deep-linked so a chart of accounts can be shared or
 * bookmarked at the section being discussed. Read from the URL rather than
 * an Inertia prop: every account is already on the client, so switching tabs
 * must not cost a round-trip to the server.
 */
function initialTab(): TabKey {
    if (typeof window === 'undefined') {
        return 'all';
    }

    const requested = new URLSearchParams(window.location.search).get('type');

    return requested !== null && isTabKey(requested) ? requested : 'all';
}

const CASH_FLOW_LABELS: Record<string, string> = {
    operating: 'Operating',
    investing: 'Investing',
    financing: 'Financing',
    none: '—',
};

/**
 * Subtype headings. `subtype` is free text on the backend (nullable string,
 * max 40) rather than an enum, so this is a display map with a fallback
 * rather than an exhaustive switch — a school that invents its own subtype
 * still gets a readable heading instead of a blank one.
 */
const SUBTYPE_LABELS: Record<string, string> = {
    current_asset: 'Current assets',
    non_current_asset: 'Non-current assets',
    contra_asset: 'Contra assets',
    current_liability: 'Current liabilities',
    non_current_liability: 'Non-current liabilities',
    equity: 'Equity',
    contra_equity: 'Contra equity',
    operating_revenue: 'Operating revenue',
    other_income: 'Other income',
    operating_expense: 'Operating expenses',
    other_expense: 'Other expenses',
};

/** Bucket for accounts saved without a subtype. */
const UNCLASSIFIED = '__unclassified__';

function subtypeLabel(key: string): string {
    if (key === UNCLASSIFIED) {
        return 'Unclassified';
    }

    const known = SUBTYPE_LABELS[key];

    if (known !== undefined) {
        return known;
    }

    const spaced = key.replaceAll('_', ' ');

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

/** An account plus its sub-accounts, ready to render as indented rows. */
type TreeNode = {
    account: ChartOfAccountRow;
    depth: number;
    children: TreeNode[];
};

/** A subtype heading and the account trees filed under it. */
type SubtypeGroup = {
    key: string;
    label: string;
    nodes: TreeNode[];
    count: number;
};

/**
 * Nest accounts by `parent_id`.
 *
 * An account whose parent is not in the current view — a different type tab,
 * or simply absent — is promoted to a root rather than dropped, so no tab can
 * silently hide an account. The `seen` guard makes a cyclic `parent_id` render
 * a finite tree instead of hanging the page; the backend should prevent one,
 * but a chart is user-editable data and a hung page is a worse failure than a
 * slightly odd one.
 */
function buildTree(accounts: ChartOfAccountRow[]): TreeNode[] {
    const present = new Set(accounts.map((account) => account.id));
    const childrenOf = new Map<number, ChartOfAccountRow[]>();
    const roots: ChartOfAccountRow[] = [];

    for (const account of accounts) {
        const parentId = account.parent_id;

        if (parentId === null || !present.has(parentId)) {
            roots.push(account);
            continue;
        }

        const siblings = childrenOf.get(parentId);

        if (siblings === undefined) {
            childrenOf.set(parentId, [account]);
        } else {
            siblings.push(account);
        }
    }

    const toNode = (
        account: ChartOfAccountRow,
        depth: number,
        seen: ReadonlySet<number>,
    ): TreeNode => {
        if (seen.has(account.id)) {
            return { account, depth, children: [] };
        }

        const nextSeen = new Set(seen).add(account.id);

        return {
            account,
            depth,
            children: (childrenOf.get(account.id) ?? []).map((child) =>
                toNode(child, depth + 1, nextSeen),
            ),
        };
    };

    return roots.map((root) => toNode(root, 0, new Set()));
}

function countNodes(nodes: TreeNode[]): number {
    return nodes.reduce(
        (total, node) => total + 1 + countNodes(node.children),
        0,
    );
}

/**
 * Group the roots by subtype, in the order the subtypes first appear.
 *
 * Order comes from the data (the server sends the chart in code order) rather
 * than a hardcoded list, because subtype is free text — a fixed order would
 * silently sort unknown subtypes last.
 *
 * Children follow their parent regardless of subtype: a sub-account filed
 * under a different heading from its parent would break the nesting, and the
 * hierarchy is the more informative of the two.
 */
function buildSubtypeGroups(accounts: ChartOfAccountRow[]): SubtypeGroup[] {
    const nodes = buildTree(accounts);
    const order: string[] = [];
    const buckets = new Map<string, TreeNode[]>();

    for (const node of nodes) {
        const subtype = node.account.subtype;
        const key = subtype === null || subtype === '' ? UNCLASSIFIED : subtype;
        const bucket = buckets.get(key);

        if (bucket === undefined) {
            buckets.set(key, [node]);
            order.push(key);
        } else {
            bucket.push(node);
        }
    }

    return order.map((key) => {
        const groupNodes = buckets.get(key) ?? [];

        return {
            key,
            label: subtypeLabel(key),
            nodes: groupNodes,
            count: countNodes(groupNodes),
        };
    });
}

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
    import: importPreview,
}: Props) {
    /*
     * The dialog reopens itself after an upload.
     *
     * Parsing needs the server — the file is diffed against the stored chart —
     * so the preview arrives on a fresh page load, by which time the dialog
     * has closed. Reopening it is what makes the modal flow work at all.
     *
     * Adjusted during render rather than in an effect, which is React's own
     * advice for reacting to a prop change: an effect here would set state
     * after paint and cascade a second render. The token is what makes it fire
     * once — every upload mints a new one, so closing the dialog and reopening
     * it manually does not get undone on the next keystroke.
     */
    const [importOpen, setImportOpen] = useState(false);
    const [shownToken, setShownToken] = useState<string | null>(null);

    if (importPreview && importPreview.token !== shownToken) {
        setShownToken(importPreview.token);
        setImportOpen(true);
    }

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

    const [activeTab, setActiveTab] = useState<TabKey>(initialTab);

    const handleTabChange = (value: string): void => {
        const next = isTabKey(value) ? value : 'all';
        setActiveTab(next);

        // replaceState, not an Inertia visit: the tab is a view of data the
        // page already holds, and pushing history would make Back walk the
        // tabs instead of leaving the page.
        const url = new URL(window.location.href);

        if (next === 'all') {
            url.searchParams.delete('type');
        } else {
            url.searchParams.set('type', next);
        }

        window.history.replaceState({}, '', url);
    };

    // Collapsed rather than expanded, so the tree opens fully by default and
    // the page still reads as the whole book without a click.
    const [collapsed, setCollapsed] = useState<ReadonlySet<string>>(new Set());

    const toggle = (key: string): void => {
        setCollapsed((previous) => {
            const next = new Set(previous);

            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return next;
        });
    };

    const rowsByType = TYPE_ORDER.map((type) => {
        const rows = accounts.filter((account) => account.type === type);

        return { type, rows, groups: buildSubtypeGroups(rows) };
    });

    // Empty types are dropped from the combined view so the book reads
    // continuously, but each keeps its tab — a type with no accounts is a
    // fact worth seeing, and a tab bar that changes shape per tenant is
    // harder to learn than one that always lists the same five.
    const populated = rowsByType.filter((section) => section.rows.length > 0);

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
                            <div className="flex items-center gap-2">
                                {/*
                                  An <a>, not a Link: the response is a file,
                                  and an Inertia visit cannot receive one.
                                */}
                                <Button asChild variant="outline">
                                    <a href="/admin/chart-of-accounts/export">
                                        <Download className="mr-1 h-4 w-4" />
                                        Export
                                    </a>
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setImportOpen(true)}
                                >
                                    <Upload className="mr-1 h-4 w-4" />
                                    Import
                                </Button>
                                <Button type="button" onClick={openCreate}>
                                    <Plus className="mr-1 h-4 w-4" />
                                    New account
                                </Button>
                            </div>
                        ) : undefined
                    }
                />

                <ChartOfAccountImportDialog
                    open={importOpen}
                    onOpenChange={setImportOpen}
                    preview={importPreview}
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
                    <Tabs value={activeTab} onValueChange={handleTabChange}>
                        <TabsList aria-label="Filter accounts by type">
                            <TabsTrigger value="all">
                                All accounts
                                <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">
                                    {accounts.length}
                                </span>
                            </TabsTrigger>
                            {rowsByType.map(({ type, rows }) => (
                                <TabsTrigger key={type} value={type}>
                                    {TYPE_LABELS[type]}
                                    <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">
                                        {rows.length}
                                    </span>
                                </TabsTrigger>
                            ))}
                        </TabsList>

                        <TabsContent value="all">
                            <AccountsTable
                                sections={populated}
                                withTypeHeadings
                                collapsed={collapsed}
                                onToggle={toggle}
                                onRequestEdit={openEdit}
                                onRequestDelete={setPendingDelete}
                            />
                        </TabsContent>

                        {rowsByType.map((section) => (
                            <TabsContent
                                key={section.type}
                                value={section.type}
                            >
                                {section.rows.length === 0 ? (
                                    <Card>
                                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                                            No{' '}
                                            {TYPE_LABELS[
                                                section.type
                                            ].toLowerCase()}{' '}
                                            accounts yet.
                                        </CardContent>
                                    </Card>
                                ) : (
                                    <AccountsTable
                                        sections={[section]}
                                        collapsed={collapsed}
                                        onToggle={toggle}
                                        onRequestEdit={openEdit}
                                        onRequestDelete={setPendingDelete}
                                    />
                                )}
                            </TabsContent>
                        ))}
                    </Tabs>
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

type Section = {
    type: AccountType;
    rows: ChartOfAccountRow[];
    groups: SubtypeGroup[];
};

type RowHandlers = {
    collapsed: ReadonlySet<string>;
    onToggle: (key: string) => void;
    onRequestEdit: (row: ChartOfAccountRow) => void;
    onRequestDelete: (row: ChartOfAccountRow) => void;
};

/**
 * The chart as a table of nested rows.
 *
 * One shared header, then a subtype heading per group and an indented row per
 * account. The combined tab passes every type and prints the type headings; a
 * per-type tab passes one and suppresses them, since the tab already names it.
 */
function AccountsTable({
    sections,
    withTypeHeadings = false,
    ...handlers
}: { sections: Section[]; withTypeHeadings?: boolean } & RowHandlers) {
    return (
        <Card>
            <CardContent className="p-0">
                <div className="overflow-x-auto">
                    <Table className="text-sm">
                        <TableHeader>
                            <TableRow className="bg-muted/40 hover:bg-muted/40">
                                <TableHead className="w-[16rem] text-xs tracking-wide text-muted-foreground uppercase">
                                    Account
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
                            {sections.map((section) => (
                                <AccountTypeSection
                                    key={section.type}
                                    section={section}
                                    showHeading={withTypeHeadings}
                                    {...handlers}
                                />
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}

function AccountTypeSection({
    section,
    showHeading = true,
    ...handlers
}: { section: Section; showHeading?: boolean } & RowHandlers) {
    return (
        <>
            {showHeading ? (
                <TableRow className="border-b bg-muted/20 hover:bg-muted/20">
                    <TableCell colSpan={7} className="py-2">
                        <span className="font-serif text-xs font-semibold tracking-wide text-foreground uppercase">
                            {TYPE_LABELS[section.type]}
                        </span>
                        <span className="ml-2 text-xs text-muted-foreground tabular-nums">
                            {section.rows.length}
                        </span>
                    </TableCell>
                </TableRow>
            ) : null}

            {section.groups.map((group) => (
                <SubtypeSection
                    key={group.key}
                    groupKey={`${section.type}:${group.key}`}
                    group={group}
                    {...handlers}
                />
            ))}
        </>
    );
}

function SubtypeSection({
    groupKey,
    group,
    ...handlers
}: { groupKey: string; group: SubtypeGroup } & RowHandlers) {
    const isOpen = !handlers.collapsed.has(groupKey);

    return (
        <>
            <TableRow className="border-b bg-muted/10 hover:bg-muted/10">
                <TableCell colSpan={7} className="py-1.5">
                    <button
                        type="button"
                        onClick={() => handlers.onToggle(groupKey)}
                        aria-expanded={isOpen}
                        className="flex items-center gap-1.5 rounded-sm text-xs font-medium text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <Disclosure open={isOpen} />
                        {group.label}
                        <span className="text-muted-foreground tabular-nums">
                            {group.count}
                        </span>
                    </button>
                </TableCell>
            </TableRow>

            {isOpen
                ? group.nodes.map((node) => (
                      <AccountNodeRows
                          key={node.account.id}
                          node={node}
                          {...handlers}
                      />
                  ))
                : null}
        </>
    );
}

/**
 * One account row, followed by its sub-accounts when expanded.
 *
 * Recursive rather than a flattened list: the nesting is the point of the
 * view, and a flat list with a depth column would put the burden of rebuilding
 * the hierarchy back on the reader.
 */
function AccountNodeRows({
    node,
    ...handlers
}: { node: TreeNode } & RowHandlers) {
    const { account: row, depth, children } = node;
    const nodeKey = `account:${row.id}`;
    const hasChildren = children.length > 0;
    const isOpen = !handlers.collapsed.has(nodeKey);

    return (
        <>
            <TableRow className={row.is_active ? undefined : 'opacity-60'}>
                <TableCell className="font-mono text-xs">
                    {/* Depth is unbounded, so indentation is computed rather
                        than picked from a fixed set of Tailwind classes. */}
                    <div
                        className="flex items-center gap-1"
                        style={{ paddingLeft: `${depth * 1.25}rem` }}
                    >
                        {hasChildren ? (
                            <button
                                type="button"
                                onClick={() => handlers.onToggle(nodeKey)}
                                aria-expanded={isOpen}
                                aria-label={`${isOpen ? 'Collapse' : 'Expand'} sub-accounts of ${row.code}`}
                                className="rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <Disclosure open={isOpen} />
                            </button>
                        ) : (
                            <span
                                className="inline-block w-3.5"
                                aria-hidden="true"
                            />
                        )}
                        {row.code}
                    </div>
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
                            onClick={() => handlers.onRequestEdit(row)}
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
                                onClick={() => handlers.onRequestDelete(row)}
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        )}
                    </div>
                </TableCell>
            </TableRow>

            {hasChildren && isOpen
                ? children.map((child) => (
                      <AccountNodeRows
                          key={child.account.id}
                          node={child}
                          {...handlers}
                      />
                  ))
                : null}
        </>
    );
}

function Disclosure({ open }: { open: boolean }) {
    return (
        <ChevronRight
            className={cn(
                'h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform duration-150 motion-reduce:transition-none',
                open && 'rotate-90',
            )}
            aria-hidden="true"
        />
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
