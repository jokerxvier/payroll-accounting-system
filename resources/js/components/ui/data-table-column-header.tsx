import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { Column } from '@tanstack/react-table';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface DataTableColumnHeaderProps<TData, TValue>
    extends React.HTMLAttributes<HTMLDivElement> {
    column: Column<TData, TValue>;
    title: string;
}

export function DataTableColumnHeader<TData, TValue>({
    column,
    title,
    className,
}: DataTableColumnHeaderProps<TData, TValue>) {
    if (!column.getCanSort()) {
        return <span className={cn('font-medium', className)}>{title}</span>;
    }

    const sorted = column.getIsSorted();
    const ariaSort =
        sorted === 'asc'
            ? 'ascending'
            : sorted === 'desc'
              ? 'descending'
              : 'none';

    return (
        <Button
            variant="ghost"
            size="sm"
            className={cn(
                '-ml-3 h-8 data-[state=open]:bg-accent',
                className,
            )}
            aria-sort={ariaSort}
            onClick={column.getToggleSortingHandler()}
        >
            <span>{title}</span>
            {sorted === 'desc' ? (
                <ArrowDown className="ml-2 h-3.5 w-3.5" />
            ) : sorted === 'asc' ? (
                <ArrowUp className="ml-2 h-3.5 w-3.5" />
            ) : (
                <ArrowUpDown className="ml-2 h-3.5 w-3.5" />
            )}
        </Button>
    );
}
