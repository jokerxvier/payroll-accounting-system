import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import InvoiceIndex from '@/pages/admin/accounting/invoices/index';
import type { InvoiceIndexProps, InvoiceRow, Paginator } from '@/types';

/*
 * The invoice list's filters.
 *
 * The search box is the widest net on the page, so what matters is that it
 * travels WITH the other filters rather than replacing them — a search that
 * silently widened the list back out to every status would be worse than no
 * search at all.
 */

const routerGet = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        get: (...args: unknown[]) => routerGet(...args),
        delete: vi.fn(),
    },
    usePage: () => ({ props: { auth: { user: null } } }),
}));

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

afterEach(() => {
    routerGet.mockClear();
    vi.useRealTimers();
});

function row(overrides: Partial<InvoiceRow> = {}): InvoiceRow {
    return {
        id: 1,
        type: 'sales',
        number: 'INV-2026-00001',
        contact_name: 'Dela Cruz Family',
        student_name: 'Juan Dela Cruz',
        issue_date: '2026-08-10',
        due_date: '2026-08-25',
        total_centavos: 500_000,
        amount_paid_centavos: 0,
        balance_due_centavos: 500_000,
        status: 'approved',
        can: { update: false, delete: false, approve: true, void: false },
        ...overrides,
    } as InvoiceRow;
}

function paginator(data: InvoiceRow[]): Paginator<InvoiceRow> {
    return {
        data,
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: data.length,
        from: 1,
        to: data.length,
    } as Paginator<InvoiceRow>;
}

function renderIndex(overrides: Partial<InvoiceIndexProps['filters']> = {}) {
    return render(
        <InvoiceIndex
            invoices={paginator([row()])}
            filters={{
                type: 'sales',
                search: null,
                contact_id: null,
                status: null,
                from: null,
                to: null,
                ...overrides,
            }}
            can={{ create: true }}
        />,
    );
}

describe('searching', () => {
    it('visits with the typed term, debounced', async () => {
        // The hook waits 300ms, so a burst of keystrokes is one request
        // rather than one per character.
        vi.useFakeTimers();

        renderIndex();

        fireEvent.change(screen.getByLabelText('Search invoices'), {
            target: { value: 'Juan' },
        });

        expect(routerGet).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(400);

        expect(routerGet).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ search: 'Juan', type: 'sales' }),
            expect.anything(),
        );
    });

    it('carries the status filter with the search rather than dropping it', async () => {
        // The whole reason the page holds one filter object.
        vi.useFakeTimers();

        renderIndex({ status: 'approved' });

        fireEvent.change(screen.getByLabelText('Search invoices'), {
            target: { value: 'Juan' },
        });

        await vi.advanceTimersByTimeAsync(400);

        expect(routerGet).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ search: 'Juan', status: 'approved' }),
            expect.anything(),
        );
    });
});

describe('arriving from the dashboard', () => {
    it('counts a linked payer as a filter, so Clear can widen it again', () => {
        // Landing here from Top Outstanding narrows the list. Without this the
        // Clear button would be hidden and the operator would be stuck on one
        // payer with no visible way out.
        renderIndex({ contact_id: 7 });

        expect(
            screen.getByRole('button', { name: /clear filters/i }),
        ).toBeInTheDocument();
    });

    it('hides Clear when nothing is filtered', () => {
        renderIndex();

        expect(
            screen.queryByRole('button', { name: /clear filters/i }),
        ).not.toBeInTheDocument();
    });
});
