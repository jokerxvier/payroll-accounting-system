import { Button } from '@/components/ui/button';
import type { PaginatorMeta } from '@/types';

interface DataTablePaginationProps {
    paginator: PaginatorMeta;
    onPageChange: (page: number) => void;
}

export function DataTablePagination({
    paginator,
    onPageChange,
}: DataTablePaginationProps) {
    const { current_page, last_page, from, to, total } = paginator;

    if (last_page <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
                Showing{' '}
                <span className="font-medium text-foreground">{from ?? 0}</span>
                –
                <span className="font-medium text-foreground">{to ?? 0}</span>{' '}
                of{' '}
                <span className="font-medium text-foreground">
                    {total.toLocaleString()}
                </span>
            </span>
            <div className="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={current_page <= 1}
                    onClick={() => onPageChange(current_page - 1)}
                >
                    Previous
                </Button>
                <span className="px-2 font-mono text-xs">
                    {current_page} / {last_page}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={current_page >= last_page}
                    onClick={() => onPageChange(current_page + 1)}
                >
                    Next
                </Button>
            </div>
        </div>
    );
}
