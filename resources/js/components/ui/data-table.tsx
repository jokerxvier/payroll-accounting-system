import {
    flexRender,
    getCoreRowModel,
    getExpandedRowModel,
    useReactTable,
    type ColumnDef,
    type ExpandedState,
    type RowData,
    type SortingState,
} from '@tanstack/react-table';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { DataTablePagination } from '@/components/ui/data-table-pagination';
import { cn } from '@/lib/utils';
import type { Paginator, SortState } from '@/types';

declare module '@tanstack/react-table' {
    interface ColumnMeta<TData extends RowData, TValue> {
        align?: 'left' | 'right' | 'center';
        headerClassName?: string;
        cellClassName?: string;
    }
}

interface DataTableEmpty {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
}

interface DataTableProps<TData> {
    columns: ColumnDef<TData, unknown>[];
    data: TData[];
    paginator: Paginator<TData> | Paginator<unknown>;
    sort?: SortState | null;
    onPageChange: (page: number) => void;
    onSortChange?: (sort: SortState | null) => void;
    empty: DataTableEmpty;
    loading?: boolean;
    toolbar?: ReactNode;
    density?: 'compact' | 'comfortable';
    onRowClick?: (row: TData) => void;
    rowKey?: (row: TData, index: number) => string | number;
    className?: string;
    /**
     * When provided, rows can be expanded to reveal a panel below them.
     * Receives the row's original data plus a `close` callback the panel
     * can call to collapse itself (e.g. on save success).
     *
     * The expand toggle button lives in a column cell; use TanStack's
     * `row.getIsExpanded()` and `row.toggleExpanded()` from the cell
     * renderer to wire it up.
     */
    renderExpandedRow?: (row: TData, close: () => void) => ReactNode;
}

const ALIGN_CLASS: Record<'left' | 'right' | 'center', string> = {
    left: '',
    right: 'text-right tabular-nums',
    center: 'text-center',
};

export function DataTable<TData>({
    columns,
    data,
    paginator,
    sort = null,
    onPageChange,
    onSortChange,
    empty,
    loading = false,
    toolbar,
    density = 'comfortable',
    onRowClick,
    rowKey,
    className,
    renderExpandedRow,
}: DataTableProps<TData>) {
    const sorting: SortingState = sort
        ? [{ id: sort.column, desc: sort.direction === 'desc' }]
        : [];
    const [expanded, setExpanded] = useState<ExpandedState>({});

    const table = useReactTable({
        data,
        columns,
        state: { sorting, expanded },
        manualPagination: true,
        manualSorting: true,
        manualFiltering: true,
        sortDescFirst: false,
        getCoreRowModel: getCoreRowModel(),
        getExpandedRowModel: getExpandedRowModel(),
        getRowCanExpand: () => Boolean(renderExpandedRow),
        onExpandedChange: setExpanded,
        onSortingChange: (updater) => {
            if (!onSortChange) return;
            const next =
                typeof updater === 'function' ? updater(sorting) : updater;
            const head = next[0];
            onSortChange(
                head
                    ? {
                          column: head.id,
                          direction: head.desc ? 'desc' : 'asc',
                      }
                    : null,
            );
        },
    });

    const cellPad = density === 'compact' ? 'px-3 py-1.5' : 'px-4 py-2.5';
    const headPad = density === 'compact' ? 'px-3 py-2' : 'px-4 py-3';

    return (
        <div className={cn('space-y-4', className)}>
            {toolbar}

            {data.length === 0 && !loading ? (
                <EmptyState
                    icon={empty.icon}
                    title={empty.title}
                    description={empty.description}
                    action={empty.action}
                />
            ) : (
                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            {table.getHeaderGroups().map((group) => (
                                <TableRow
                                    key={group.id}
                                    className="bg-muted/40 hover:bg-muted/40"
                                >
                                    {group.headers.map((header) => {
                                        const align =
                                            header.column.columnDef.meta
                                                ?.align ?? 'left';
                                        const customClass =
                                            header.column.columnDef.meta
                                                ?.headerClassName;
                                        return (
                                            <TableHead
                                                key={header.id}
                                                className={cn(
                                                    headPad,
                                                    'text-xs font-medium uppercase tracking-wide text-muted-foreground',
                                                    ALIGN_CLASS[align],
                                                    customClass,
                                                )}
                                            >
                                                {header.isPlaceholder
                                                    ? null
                                                    : flexRender(
                                                          header.column
                                                              .columnDef.header,
                                                          header.getContext(),
                                                      )}
                                            </TableHead>
                                        );
                                    })}
                                </TableRow>
                            ))}
                        </TableHeader>
                        <TableBody>
                            {loading
                                ? Array.from({ length: 5 }).map((_, i) => (
                                      <TableRow key={`skeleton-${i}`}>
                                          {columns.map((_col, c) => (
                                              <TableCell
                                                  key={c}
                                                  className={cn(cellPad)}
                                              >
                                                  <div className="h-4 w-3/4 animate-pulse rounded bg-muted" />
                                              </TableCell>
                                          ))}
                                      </TableRow>
                                  ))
                                : table.getRowModel().rows.map((row, idx) => {
                                      const baseKey = rowKey
                                          ? String(rowKey(row.original, idx))
                                          : row.id;
                                      const isExpanded = row.getIsExpanded();

                                      return (
                                          <Fragment key={baseKey}>
                                              <TableRow
                                                  className={cn(
                                                      onRowClick &&
                                                          'cursor-pointer',
                                                      isExpanded &&
                                                          'border-b-0 bg-muted/30',
                                                  )}
                                                  onClick={
                                                      onRowClick
                                                          ? () =>
                                                                onRowClick(
                                                                    row.original,
                                                                )
                                                          : undefined
                                                  }
                                              >
                                                  {row
                                                      .getVisibleCells()
                                                      .map((cell) => {
                                                          const align =
                                                              cell.column
                                                                  .columnDef
                                                                  .meta?.align ??
                                                              'left';
                                                          const customClass =
                                                              cell.column
                                                                  .columnDef
                                                                  .meta
                                                                  ?.cellClassName;
                                                          return (
                                                              <TableCell
                                                                  key={cell.id}
                                                                  className={cn(
                                                                      cellPad,
                                                                      ALIGN_CLASS[
                                                                          align
                                                                      ],
                                                                      customClass,
                                                                  )}
                                                              >
                                                                  {flexRender(
                                                                      cell.column
                                                                          .columnDef
                                                                          .cell,
                                                                      cell.getContext(),
                                                                  )}
                                                              </TableCell>
                                                          );
                                                      })}
                                              </TableRow>
                                              {isExpanded &&
                                                  renderExpandedRow && (
                                                      <TableRow className="bg-muted/30 hover:bg-muted/30">
                                                          <TableCell
                                                              colSpan={
                                                                  row.getVisibleCells()
                                                                      .length
                                                              }
                                                              className="p-0"
                                                          >
                                                              <div className="border-t bg-background p-4">
                                                                  {renderExpandedRow(
                                                                      row.original,
                                                                      () =>
                                                                          row.toggleExpanded(
                                                                              false,
                                                                          ),
                                                                  )}
                                                              </div>
                                                          </TableCell>
                                                      </TableRow>
                                                  )}
                                          </Fragment>
                                      );
                                  })}
                        </TableBody>
                    </Table>
                </div>
            )}

            <DataTablePagination
                paginator={paginator}
                onPageChange={onPageChange}
            />
        </div>
    );
}
