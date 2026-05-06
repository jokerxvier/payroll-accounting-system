import { Head, router } from '@inertiajs/react';
import { Download, FileSearch, X } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

interface AuditEntry {
    id: number;
    created_at: string | null;
    action: string;
    auditable_type: string | null;
    auditable_short_type: string | null;
    auditable_id: number | null;
    actor_id: number | null;
    actor_name: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    ip: string | null;
    user_agent: string | null;
}

interface AuditableTypeOption {
    value: string;
    label: string;
}

interface Filters {
    from: string | null;
    to: string | null;
    actor_id: number | null;
    action: string | null;
    auditable_type: string | null;
}

interface Paginated {
    data: AuditEntry[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Props {
    filters: Filters;
    entries: Paginated;
    distinctActions: string[];
    distinctAuditableTypes: AuditableTypeOption[];
}

const ALL = '__all__';

function formatDateTime(iso: string | null): string {
    if (iso === null) return '—';
    return new Date(iso).toLocaleString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

function summariseChange(
    before: Record<string, unknown> | null,
    after: Record<string, unknown> | null,
): string {
    if (before === null && after !== null) {
        return 'Created';
    }
    if (before !== null && after === null) {
        return 'Deleted';
    }
    if (before !== null && after !== null) {
        const keys = new Set([...Object.keys(before), ...Object.keys(after)]);
        return `${keys.size} field${keys.size === 1 ? '' : 's'} changed`;
    }
    return '—';
}

export default function AuditLogIndex({
    filters,
    entries,
    distinctActions,
    distinctAuditableTypes,
}: Props) {
    const [draft, setDraft] = useState({
        from: filters.from ?? '',
        to: filters.to ?? '',
        action: filters.action ?? '',
        auditable_type: filters.auditable_type ?? '',
    });
    const [selected, setSelected] = useState<AuditEntry | null>(null);

    const apply = (e: React.FormEvent) => {
        e.preventDefault();
        const query: Record<string, string> = {};
        if (draft.from) query.from = draft.from;
        if (draft.to) query.to = draft.to;
        if (draft.action) query.action = draft.action;
        if (draft.auditable_type) query.auditable_type = draft.auditable_type;
        router.get('/admin/audit-logs', query, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const reset = () => {
        setDraft({ from: '', to: '', action: '', auditable_type: '' });
        router.get('/admin/audit-logs', {}, { preserveScroll: true });
    };

    const buildExportUrl = (): string => {
        const params = new URLSearchParams();
        if (draft.from) params.set('from', draft.from);
        if (draft.to) params.set('to', draft.to);
        if (draft.action) params.set('action', draft.action);
        if (draft.auditable_type)
            params.set('auditable_type', draft.auditable_type);
        const query = params.toString();
        return query
            ? `/admin/audit-logs/export?${query}`
            : '/admin/audit-logs/export';
    };

    const goPage = (page: number) => {
        const query: Record<string, string | number> = { page };
        if (filters.from) query.from = filters.from;
        if (filters.to) query.to = filters.to;
        if (filters.action) query.action = filters.action;
        if (filters.auditable_type)
            query.auditable_type = filters.auditable_type;
        router.get('/admin/audit-logs', query, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Audit log" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="AUDIT"
                    title="Audit log"
                    description="Append-only trail of every payroll-affecting action. Click a row for the full before/after diff."
                    actions={
                        <Button asChild variant="outline" size="sm">
                            <a href={buildExportUrl()}>
                                <Download className="mr-1 h-4 w-4" />
                                Export CSV
                            </a>
                        </Button>
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm font-medium">
                            Filter
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={apply}
                            className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                        >
                            <div className="space-y-1">
                                <Label htmlFor="from">From</Label>
                                <Input
                                    id="from"
                                    type="date"
                                    value={draft.from}
                                    onChange={(e) =>
                                        setDraft((d) => ({
                                            ...d,
                                            from: e.target.value,
                                        }))
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="to">To</Label>
                                <Input
                                    id="to"
                                    type="date"
                                    value={draft.to}
                                    onChange={(e) =>
                                        setDraft((d) => ({
                                            ...d,
                                            to: e.target.value,
                                        }))
                                    }
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="action">Action</Label>
                                <Select
                                    value={draft.action || ALL}
                                    onValueChange={(v) =>
                                        setDraft((d) => ({
                                            ...d,
                                            action: v === ALL ? '' : v,
                                        }))
                                    }
                                >
                                    <SelectTrigger id="action">
                                        <SelectValue placeholder="Any" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            Any action
                                        </SelectItem>
                                        {distinctActions.map((a) => (
                                            <SelectItem key={a} value={a}>
                                                <span className="font-mono text-xs">
                                                    {a}
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="auditable_type">Target</Label>
                                <Select
                                    value={draft.auditable_type || ALL}
                                    onValueChange={(v) =>
                                        setDraft((d) => ({
                                            ...d,
                                            auditable_type: v === ALL ? '' : v,
                                        }))
                                    }
                                >
                                    <SelectTrigger id="auditable_type">
                                        <SelectValue placeholder="Any" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            Any target
                                        </SelectItem>
                                        {distinctAuditableTypes.map((t) => (
                                            <SelectItem
                                                key={t.value}
                                                value={t.value}
                                            >
                                                {t.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex items-end gap-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    className="flex-1"
                                >
                                    Apply
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={reset}
                                >
                                    <X className="h-4 w-4" />
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-sm font-medium">
                            <FileSearch className="h-4 w-4" />
                            Entries
                        </CardTitle>
                        <p className="text-xs text-muted-foreground">
                            {entries.total} entr
                            {entries.total === 1 ? 'y' : 'ies'} · Page{' '}
                            {entries.current_page} of {entries.last_page}
                        </p>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-md border">
                            <Table className="text-sm">
                                <TableHeader>
                                    <TableRow className="bg-muted/40 hover:bg-muted/40">
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Timestamp
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Actor
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Action
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Target
                                        </TableHead>
                                        <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Change
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {entries.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={5}
                                                className="py-8 text-center text-sm text-muted-foreground"
                                            >
                                                No audit entries match the
                                                current filter.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        entries.data.map((entry) => (
                                            <TableRow
                                                key={entry.id}
                                                className="cursor-pointer transition-colors hover:bg-muted/30"
                                                onClick={() =>
                                                    setSelected(entry)
                                                }
                                            >
                                                <TableCell className="text-xs whitespace-nowrap tabular-nums">
                                                    {formatDateTime(
                                                        entry.created_at,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {entry.actor_name ?? (
                                                        <span className="text-xs text-muted-foreground">
                                                            System
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant="outline"
                                                        className="font-mono text-xs"
                                                    >
                                                        {entry.action}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-xs">
                                                    {entry.auditable_short_type ??
                                                        '—'}
                                                    {entry.auditable_id
                                                        ? ` #${entry.auditable_id}`
                                                        : ''}
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground">
                                                    {summariseChange(
                                                        entry.before,
                                                        entry.after,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {entries.last_page > 1 ? (
                            <div className="mt-4 flex items-center justify-between text-xs text-muted-foreground">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={entries.current_page === 1}
                                    onClick={() =>
                                        goPage(entries.current_page - 1)
                                    }
                                >
                                    Previous
                                </Button>
                                <span>
                                    Page {entries.current_page} of{' '}
                                    {entries.last_page}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        entries.current_page ===
                                        entries.last_page
                                    }
                                    onClick={() =>
                                        goPage(entries.current_page + 1)
                                    }
                                >
                                    Next
                                </Button>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </div>

            <Sheet
                open={selected !== null}
                onOpenChange={(open) => !open && setSelected(null)}
            >
                <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                    <SheetHeader>
                        <SheetTitle className="font-mono text-base">
                            {selected?.action}
                        </SheetTitle>
                        <SheetDescription className="space-y-0.5 text-xs">
                            <span className="block">
                                {formatDateTime(selected?.created_at ?? null)}
                            </span>
                            <span className="block">
                                Actor:{' '}
                                {selected?.actor_name ?? (
                                    <span className="italic">System</span>
                                )}
                                {selected?.actor_id
                                    ? ` (#${selected.actor_id})`
                                    : ''}
                            </span>
                            <span className="block">
                                Target: {selected?.auditable_short_type ?? '—'}
                                {selected?.auditable_id
                                    ? ` #${selected.auditable_id}`
                                    : ''}
                            </span>
                            {selected?.ip ? (
                                <span className="block">
                                    IP:{' '}
                                    <span className="font-mono">
                                        {selected.ip}
                                    </span>
                                </span>
                            ) : null}
                        </SheetDescription>
                    </SheetHeader>

                    <div className="mt-6 space-y-4">
                        <div>
                            <h4 className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                Before
                            </h4>
                            {selected?.before ? (
                                <pre className="overflow-x-auto rounded-md border bg-muted/30 p-3 text-xs leading-relaxed">
                                    {JSON.stringify(selected.before, null, 2)}
                                </pre>
                            ) : (
                                <p className="text-xs text-muted-foreground italic">
                                    No previous state (creation event).
                                </p>
                            )}
                        </div>

                        <div>
                            <h4 className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                After
                            </h4>
                            {selected?.after ? (
                                <pre className="overflow-x-auto rounded-md border bg-muted/30 p-3 text-xs leading-relaxed">
                                    {JSON.stringify(selected.after, null, 2)}
                                </pre>
                            ) : (
                                <p className="text-xs text-muted-foreground italic">
                                    No new state (deletion event).
                                </p>
                            )}
                        </div>

                        {selected?.user_agent ? (
                            <div>
                                <h4 className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    User agent
                                </h4>
                                <p className="font-mono text-[10px] break-words text-muted-foreground">
                                    {selected.user_agent}
                                </p>
                            </div>
                        ) : null}
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}

AuditLogIndex.layout = {
    breadcrumbs: [
        { title: 'Audit', href: '/admin/audit-logs' },
        { title: 'Audit log', href: '/admin/audit-logs' },
    ],
};
