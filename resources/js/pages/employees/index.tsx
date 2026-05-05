import { Head, Link, router } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import {
    ArrowRight,
    ChevronDown,
    ChevronRight,
    Search,
    Sparkles,
    Users,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { EmployeeRowEditor } from '@/components/employees/employee-row-editor';
import { InlineMoneyEdit } from '@/components/inline-money-edit';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import { DataTableColumnHeader } from '@/components/ui/data-table-column-header';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTableFilters } from '@/hooks/use-table-filters';
import {
    index as employeesIndex,
    show as employeesShow,
} from '@/routes/employees';
import { update as profileUpdate } from '@/routes/employees/profile';
import type {
    DepartmentOption,
    EmployeeListFilters,
    EmployeeRow,
    EmploymentClassification,
    EmploymentTypeOption,
    Paginator,
    SortState,
} from '@/types';

interface Props {
    employees: Paginator<EmployeeRow>;
    filters: EmployeeListFilters;
    departmentOptions: DepartmentOption[];
    employmentTypeOptions: EmploymentTypeOption[];
    can?: { seedDemoSalaries?: boolean };
}

const PATH = '/employees';
const ALL = '__all__';

function buildColumns(): ColumnDef<EmployeeRow>[] {
    return [
        {
            accessorKey: 'staff_no',
            header: ({ column }) => (
                <DataTableColumnHeader column={column} title="Staff no." />
            ),
            enableSorting: true,
            meta: { headerClassName: 'w-[140px]' },
            cell: ({ row }) => (
                <span className="font-mono text-xs">
                    {row.original.staff_no ?? '—'}
                </span>
            ),
        },
        {
            accessorKey: 'full_name',
            header: ({ column }) => (
                <DataTableColumnHeader column={column} title="Name" />
            ),
            enableSorting: true,
            cell: ({ row }) => (
                <span className="font-medium">
                    {row.original.full_name ?? '—'}
                </span>
            ),
        },
        {
            accessorKey: 'email',
            header: 'Email',
            cell: ({ row }) => (
                <span className="text-muted-foreground">
                    {row.original.email ?? '—'}
                </span>
            ),
        },
        {
            accessorKey: 'employment_classification',
            header: 'Type',
            cell: ({ row }) => {
                const value = row.original.employment_classification;

                return value ? (
                    <span className="capitalize">
                        {value.replace('_', ' ')}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                );
            },
        },
        {
            id: 'status',
            header: 'Status',
            cell: ({ row }) => (
                <StatusBadge
                    hasProfile={row.original.has_profile}
                    isActive={row.original.is_active}
                />
            ),
        },
        {
            accessorKey: 'basic_salary_centavos',
            header: 'Basic salary',
            meta: { align: 'right' },
            cell: ({ row }) =>
                row.original.has_profile ? (
                    <InlineMoneyEdit
                        valueCentavos={row.original.basic_salary_centavos}
                        label="Basic salary"
                        onSave={(centavos) =>
                            new Promise<void>((resolve, reject) => {
                                router.patch(
                                    profileUpdate(row.original.lms_staff_id)
                                        .url,
                                    { basic_salary_centavos: centavos },
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                        onSuccess: () => {
                                            toast.success('Salary updated');
                                            resolve();
                                        },
                                        onError: (errors) => {
                                            toast.error(
                                                errors.basic_salary_centavos ??
                                                    'Could not save',
                                            );
                                            reject(
                                                new Error(
                                                    errors.basic_salary_centavos ??
                                                        'save_failed',
                                                ),
                                            );
                                        },
                                    },
                                );
                            })
                        }
                    />
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            id: 'actions',
            header: '',
            meta: { align: 'right', headerClassName: 'w-[260px]' },
            cell: ({ row }) => {
                const expanded = row.getIsExpanded();

                return (
                    <div className="flex justify-end gap-2">
                        {row.original.has_profile && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => row.toggleExpanded()}
                                aria-expanded={expanded}
                            >
                                {expanded ? (
                                    <ChevronDown className="mr-1 h-3.5 w-3.5" />
                                ) : (
                                    <ChevronRight className="mr-1 h-3.5 w-3.5" />
                                )}
                                Quick edit
                            </Button>
                        )}
                        <Button asChild size="sm" variant="outline">
                            <Link
                                href={
                                    employeesShow(row.original.lms_staff_id).url
                                }
                            >
                                View profile
                                <ArrowRight className="ml-1 h-3.5 w-3.5" />
                            </Link>
                        </Button>
                    </div>
                );
            },
        },
    ];
}

export default function EmployeesIndex({
    employees,
    filters: initialFilters,
    departmentOptions,
    employmentTypeOptions,
    can,
}: Props) {
    const { filters, apply, applyDebounced, reset } =
        useTableFilters<EmployeeListFilters>(initialFilters, PATH);
    const [searchValue, setSearchValue] = useState(initialFilters.search ?? '');
    const [seedDialogOpen, setSeedDialogOpen] = useState(false);
    const [seeding, setSeeding] = useState(false);

    const columns = useMemo(() => buildColumns(), []);

    const sort: SortState | null = filters.sort_by
        ? {
              column: filters.sort_by,
              direction: filters.sort_dir ?? 'asc',
          }
        : null;

    const hasActiveFilter =
        Boolean(filters.search) ||
        Boolean(filters.status) ||
        Boolean(filters.department_id) ||
        Boolean(filters.employment_classification);

    const totalLabel = `${employees.total.toLocaleString()} ${
        employees.total === 1 ? 'employee' : 'employees'
    }`;
    const profileCount = employees.data.filter((e) => e.has_profile).length;
    const activeCount = employees.data.filter(
        (e) => e.is_active === true,
    ).length;

    const toolbar = (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="relative w-full sm:max-w-sm">
                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    type="search"
                    placeholder="Search by name, staff no, or email"
                    value={searchValue}
                    onChange={(e) => {
                        setSearchValue(e.target.value);
                        applyDebounced({
                            search: e.target.value || undefined,
                        });
                    }}
                    className="pl-9"
                />
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <Select
                    value={filters.status ?? ALL}
                    onValueChange={(v) =>
                        apply({
                            status:
                                v === ALL
                                    ? undefined
                                    : (v as 'active' | 'inactive'),
                        })
                    }
                >
                    <SelectTrigger className="w-[140px]">
                        <SelectValue placeholder="Status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All status</SelectItem>
                        <SelectItem value="active">Active</SelectItem>
                        <SelectItem value="inactive">Inactive</SelectItem>
                    </SelectContent>
                </Select>

                <Select
                    value={
                        filters.department_id !== undefined
                            ? String(filters.department_id)
                            : ALL
                    }
                    onValueChange={(v) =>
                        apply({
                            department_id: v === ALL ? undefined : Number(v),
                        })
                    }
                >
                    <SelectTrigger className="w-[200px]">
                        <SelectValue placeholder="Department" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All departments</SelectItem>
                        {departmentOptions.map((d) => (
                            <SelectItem key={d.id} value={String(d.id)}>
                                {d.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select
                    value={filters.employment_classification ?? ALL}
                    onValueChange={(v) =>
                        apply({
                            employment_classification:
                                v === ALL
                                    ? undefined
                                    : (v as EmploymentClassification),
                        })
                    }
                >
                    <SelectTrigger className="w-[160px]">
                        <SelectValue placeholder="Employment type" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All types</SelectItem>
                        {employmentTypeOptions.map((o) => (
                            <SelectItem key={o.value} value={o.value}>
                                {o.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {hasActiveFilter && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setSearchValue('');
                            reset();
                        }}
                    >
                        <X className="mr-1 h-4 w-4" />
                        Clear
                    </Button>
                )}
            </div>
        </div>
    );

    return (
        <>
            <Head title="Employees" />

            <div className="space-y-6 p-4">
                <PageHeader
                    eyebrow="DIRECTORY"
                    title="Employees"
                    description={`${totalLabel} drawn from the LMS staff roster`}
                    actions={
                        can?.seedDemoSalaries ? (
                            <AlertDialog
                                open={seedDialogOpen}
                                onOpenChange={setSeedDialogOpen}
                            >
                                <AlertDialogTrigger asChild>
                                    <Button variant="outline" size="sm">
                                        <Sparkles className="mr-1 h-3.5 w-3.5" />
                                        Seed demo salaries
                                    </Button>
                                </AlertDialogTrigger>
                                <AlertDialogContent>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            Seed demo salaries
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            Assigns a random round-thousand
                                            basic salary (₱25,000 – ₱75,000) to
                                            every active employee currently at
                                            zero. Idempotent — non-zero rows are
                                            never touched. Dev/demo only;
                                            disabled in production.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
                                        </AlertDialogCancel>
                                        <AlertDialogAction
                                            disabled={seeding}
                                            onClick={(e) => {
                                                e.preventDefault();
                                                setSeeding(true);
                                                router.post(
                                                    '/admin/dev/seed-demo-salaries',
                                                    {},
                                                    {
                                                        preserveScroll: true,
                                                        onFinish: () => {
                                                            setSeeding(false);
                                                            setSeedDialogOpen(
                                                                false,
                                                            );
                                                        },
                                                    },
                                                );
                                            }}
                                        >
                                            {seeding
                                                ? 'Seeding…'
                                                : 'Seed salaries'}
                                        </AlertDialogAction>
                                    </AlertDialogFooter>
                                </AlertDialogContent>
                            </AlertDialog>
                        ) : undefined
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatCard
                        label="Total on this page"
                        value={employees.data.length}
                        hint={`Page ${employees.current_page} of ${employees.last_page}`}
                    />
                    <StatCard
                        label="With payroll profile"
                        value={profileCount}
                        hint={
                            employees.data.length > 0
                                ? `${Math.round(
                                      (profileCount / employees.data.length) *
                                          100,
                                  )}% of this page`
                                : '—'
                        }
                    />
                    <StatCard
                        label="Active"
                        value={activeCount}
                        hint="Profile flagged active"
                    />
                </div>

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <DataTable
                            columns={columns}
                            data={employees.data}
                            paginator={employees}
                            sort={sort}
                            onPageChange={(page) => apply({ page })}
                            onSortChange={(s) =>
                                apply({
                                    sort_by: s?.column as
                                        | 'staff_no'
                                        | 'full_name'
                                        | undefined,
                                    sort_dir: s?.direction,
                                })
                            }
                            empty={{
                                icon: Users,
                                title: hasActiveFilter
                                    ? 'No employees match these filters'
                                    : 'No employees yet',
                                description: hasActiveFilter
                                    ? 'Try clearing a filter or adjusting your search.'
                                    : 'Once LMS staff are linked to payroll profiles, they will appear here.',
                                action: hasActiveFilter && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearchValue('');
                                            reset();
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                ),
                            }}
                            rowKey={(row) => row.lms_staff_id}
                            renderExpandedRow={(row, close) => (
                                <EmployeeRowEditor
                                    staffId={row.lms_staff_id}
                                    fullName={row.full_name}
                                    employmentTypeOptions={
                                        employmentTypeOptions
                                    }
                                    onClose={close}
                                />
                            )}
                            toolbar={toolbar}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

EmployeesIndex.layout = {
    breadcrumbs: [{ title: 'Employees', href: employeesIndex().url }],
};

function StatusBadge({
    hasProfile,
    isActive,
}: {
    hasProfile: boolean;
    isActive: boolean | null;
}) {
    if (!hasProfile) {
        return (
            <Badge variant="outline" className="text-muted-foreground">
                No profile
            </Badge>
        );
    }

    if (isActive) {
        return (
            <Badge className="bg-success/15 text-success hover:bg-success/15">
                Active
            </Badge>
        );
    }

    return (
        <Badge className="bg-warning/15 text-warning hover:bg-warning/15">
            Inactive
        </Badge>
    );
}
