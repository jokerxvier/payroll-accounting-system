import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { ColumnDef } from '@tanstack/react-table';
import { Inbox } from 'lucide-react';
import { DataTable } from '@/components/ui/data-table';
import { DataTableColumnHeader } from '@/components/ui/data-table-column-header';
import type { Paginator } from '@/types';

type Row = { id: number; name: string; amount_centavos: number };

function makePaginator(data: Row[], overrides: Partial<Paginator<Row>> = {}): Paginator<Row> {
    return {
        data,
        current_page: 1,
        last_page: 1,
        per_page: 25,
        from: data.length > 0 ? 1 : null,
        to: data.length > 0 ? data.length : null,
        total: data.length,
        links: [],
        ...overrides,
    };
}

const baseColumns: ColumnDef<Row>[] = [
    { accessorKey: 'name', header: 'Name' },
    {
        accessorKey: 'amount_centavos',
        header: ({ column }) => (
            <DataTableColumnHeader column={column} title="Amount" />
        ),
        enableSorting: true,
        meta: { align: 'right' },
        cell: ({ row }) => (
            <span data-testid={`amount-${row.original.id}`}>
                {row.original.amount_centavos}
            </span>
        ),
    },
];

describe('DataTable', () => {
    it('renders the empty state when there is no data', () => {
        render(
            <DataTable
                columns={baseColumns}
                data={[]}
                paginator={makePaginator([])}
                onPageChange={vi.fn()}
                empty={{ icon: Inbox, title: 'No rows', description: 'Try again later.' }}
            />,
        );

        expect(screen.getByText('No rows')).toBeInTheDocument();
        expect(screen.getByText('Try again later.')).toBeInTheDocument();
    });

    it('renders rows with column cells', () => {
        const rows: Row[] = [
            { id: 1, name: 'Ada', amount_centavos: 12_345 },
            { id: 2, name: 'Linus', amount_centavos: 67_890 },
        ];

        render(
            <DataTable
                columns={baseColumns}
                data={rows}
                paginator={makePaginator(rows)}
                onPageChange={vi.fn()}
                empty={{ title: 'empty' }}
                rowKey={(r) => r.id}
            />,
        );

        expect(screen.getByText('Ada')).toBeInTheDocument();
        expect(screen.getByText('Linus')).toBeInTheDocument();
        expect(screen.getByTestId('amount-1')).toHaveTextContent('12345');
    });

    it('emits onPageChange when Next is clicked', () => {
        const rows: Row[] = [{ id: 1, name: 'Ada', amount_centavos: 1 }];
        const onPageChange = vi.fn();

        render(
            <DataTable
                columns={baseColumns}
                data={rows}
                paginator={makePaginator(rows, {
                    current_page: 1,
                    last_page: 3,
                    total: 75,
                    from: 1,
                    to: 25,
                })}
                onPageChange={onPageChange}
                empty={{ title: 'empty' }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /next/i }));
        expect(onPageChange).toHaveBeenCalledWith(2);
    });

    it('emits onSortChange with column + direction when a sortable header is clicked', () => {
        const rows: Row[] = [{ id: 1, name: 'Ada', amount_centavos: 1 }];
        const onSortChange = vi.fn();

        render(
            <DataTable
                columns={baseColumns}
                data={rows}
                paginator={makePaginator(rows)}
                onPageChange={vi.fn()}
                onSortChange={onSortChange}
                sort={null}
                empty={{ title: 'empty' }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /amount/i }));
        expect(onSortChange).toHaveBeenCalledWith({
            column: 'amount_centavos',
            direction: 'asc',
        });
    });

    it('toggles sort direction when the same header is clicked twice', () => {
        const rows: Row[] = [{ id: 1, name: 'Ada', amount_centavos: 1 }];
        const onSortChange = vi.fn();

        render(
            <DataTable
                columns={baseColumns}
                data={rows}
                paginator={makePaginator(rows)}
                onPageChange={vi.fn()}
                onSortChange={onSortChange}
                sort={{ column: 'amount_centavos', direction: 'asc' }}
                empty={{ title: 'empty' }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /amount/i }));
        expect(onSortChange).toHaveBeenCalledWith({
            column: 'amount_centavos',
            direction: 'desc',
        });
    });

    it('hides the pagination row when there is only one page', () => {
        const rows: Row[] = [{ id: 1, name: 'Ada', amount_centavos: 1 }];

        render(
            <DataTable
                columns={baseColumns}
                data={rows}
                paginator={makePaginator(rows)}
                onPageChange={vi.fn()}
                empty={{ title: 'empty' }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /previous/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /next/i }),
        ).not.toBeInTheDocument();
    });
});
