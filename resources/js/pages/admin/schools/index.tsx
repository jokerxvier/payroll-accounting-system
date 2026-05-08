import { Head, Link, router } from '@inertiajs/react';
import { Building2, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    create as schoolsCreate,
    destroy as schoolsDestroy,
    edit as schoolsEdit,
    index as schoolsIndex,
} from '@/routes/admin/schools';
import type { Paginator, School } from '@/types';

type StatusFilter = 'all' | 'active' | 'inactive';

interface Filters {
    search: string;
    status: StatusFilter;
}

interface Props {
    schools: Paginator<School>;
    filters: Filters;
}

const DEFAULT_SLUG = 'default';

export default function SchoolsIndex({ schools, filters }: Props) {
    const [pendingDelete, setPendingDelete] = useState<School | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState<StatusFilter>(filters.status);

    // Debounced search — push the query string after the user pauses typing
    // so we're not firing a request on every keystroke.
    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = window.setTimeout(() => {
            router.get(
                schoolsIndex().url,
                { search, status },
                { preserveScroll: true, preserveState: true, replace: true },
            );
        }, 300);

        return () => window.clearTimeout(timer);
        // status intentionally excluded — its own onChange handles it instantly.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const handleStatusChange = (next: string): void => {
        const value = next as StatusFilter;
        setStatus(value);
        router.get(
            schoolsIndex().url,
            { search, status: value },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const handleConfirmDelete = (): void => {
        if (pendingDelete === null) {
            return;
        }

        setIsDeleting(true);

        router.delete(schoolsDestroy({ school: pendingDelete.id }).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(`Deleted '${pendingDelete.name}'.`);
                setPendingDelete(null);
            },
            onError: () => {
                toast.error(
                    'Could not delete this school. Refresh and try again.',
                );
            },
            onFinish: () => {
                setIsDeleting(false);
            },
        });
    };

    const goPage = (page: number): void => {
        router.get(
            schoolsIndex().url,
            { page, search, status },
            { preserveScroll: true, preserveState: true },
        );
    };

    const isFiltered = search !== '' || status !== 'all';

    return (
        <>
            <Head title="Schools" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="ADMIN"
                    title="Schools"
                    description="Tenant registry for the multi-school payroll deployment. Each row owns the LMS connection credentials for one school."
                    actions={
                        <Button asChild>
                            <Link href={schoolsCreate().url}>
                                <Plus className="mr-1 h-4 w-4" />
                                New school
                            </Link>
                        </Button>
                    }
                />

                <Card>
                    <CardContent className="flex flex-col gap-3 p-3 sm:flex-row sm:items-center">
                        <div className="relative flex-1">
                            <Search
                                className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <Input
                                type="search"
                                placeholder="Search by name, slug, or domain…"
                                className="pl-9"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                aria-label="Search schools"
                            />
                        </div>
                        <Select
                            value={status}
                            onValueChange={handleStatusChange}
                        >
                            <SelectTrigger className="sm:w-44">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All schools</SelectItem>
                                <SelectItem value="active">
                                    Active only
                                </SelectItem>
                                <SelectItem value="inactive">
                                    Inactive only
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </CardContent>
                </Card>

                {schools.data.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title={
                            isFiltered
                                ? 'No schools match your filters'
                                : 'No schools registered'
                        }
                        description={
                            isFiltered
                                ? 'Try clearing the search or switching the status filter.'
                                : 'Add the first tenant to make it resolvable by domain or path-prefix.'
                        }
                        action={
                            isFiltered ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        setSearch('');
                                        handleStatusChange('all');
                                    }}
                                >
                                    Clear filters
                                </Button>
                            ) : (
                                <Button asChild size="sm">
                                    <Link href={schoolsCreate().url}>
                                        <Plus className="mr-1 h-4 w-4" />
                                        New school
                                    </Link>
                                </Button>
                            )
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
                                                Name
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Slug
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Domain
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Host : Port
                                            </TableHead>
                                            <TableHead className="text-xs tracking-wide text-muted-foreground uppercase">
                                                Database
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
                                        {schools.data.map((row) => {
                                            const isDefault =
                                                row.slug === DEFAULT_SLUG;

                                            return (
                                                <TableRow key={row.id}>
                                                    <TableCell className="font-medium">
                                                        {row.name}
                                                        {isDefault ? (
                                                            <Badge
                                                                variant="outline"
                                                                className="ml-2 text-[10px]"
                                                            >
                                                                Default
                                                            </Badge>
                                                        ) : null}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs">
                                                        {row.slug}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs">
                                                        {row.domain ?? (
                                                            <span className="text-muted-foreground">
                                                                —
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs">
                                                        {row.lms_db_host}
                                                        <span className="text-muted-foreground">
                                                            :
                                                        </span>
                                                        <span className="tabular-nums">
                                                            {row.lms_db_port}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs">
                                                        {row.lms_db_database}
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
                                                                aria-label={`Edit ${row.name}`}
                                                            >
                                                                <Link
                                                                    href={
                                                                        schoolsEdit(
                                                                            {
                                                                                school: row.id,
                                                                            },
                                                                        ).url
                                                                    }
                                                                >
                                                                    <Pencil className="h-4 w-4" />
                                                                </Link>
                                                            </Button>
                                                            {isDefault ? null : (
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                                    aria-label={`Delete ${row.name}`}
                                                                    onClick={() =>
                                                                        setPendingDelete(
                                                                            row,
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>

                            {schools.last_page > 1 ? (
                                <div className="flex items-center justify-between border-t p-3 text-xs text-muted-foreground">
                                    <span>
                                        Showing {schools.from ?? 0}–
                                        {schools.to ?? 0} of {schools.total}
                                    </span>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                schools.current_page === 1
                                            }
                                            onClick={() =>
                                                goPage(schools.current_page - 1)
                                            }
                                        >
                                            Previous
                                        </Button>
                                        <span className="tabular-nums">
                                            Page {schools.current_page} of{' '}
                                            {schools.last_page}
                                        </span>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                schools.current_page ===
                                                schools.last_page
                                            }
                                            onClick={() =>
                                                goPage(schools.current_page + 1)
                                            }
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
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
                        <AlertDialogTitle>Delete this school?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {pendingDelete
                                ? `'${pendingDelete.name}' (${pendingDelete.slug}) will be removed from the tenant registry. Existing payroll data attached to this school will lose its tenant binding.`
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

SchoolsIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: '#' },
        { title: 'Schools', href: schoolsIndex().url },
    ],
};
