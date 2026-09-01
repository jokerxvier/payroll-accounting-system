import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import JournalIndex from '@/pages/admin/accounting/journal/index';
import type { JournalEntryRow, Paginator } from '@/types';

const routerGet = vi.fn();
const routerDelete = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        get: (...args: unknown[]) => routerGet(...args),
        delete: (...args: unknown[]) => routerDelete(...args),
    },
    usePage: () => ({ props: { auth: { user: null } } }),
}));

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function row(overrides: Partial<JournalEntryRow> = {}): JournalEntryRow {
    return {
        id: 1,
        entry_number: 'JE-2026-00001',
        date: '2026-08-03',
        reference: 'DEMO-001',
        narration: 'Tuition collected',
        status: 'posted',
        period_code: '2026-08',
        total_debit_centavos: 28_000_000,
        total_credit_centavos: 28_000_000,
        has_been_reversed: false,
        is_reversal: false,
        can: { update: false, delete: false, reverse: true },
        ...overrides,
    };
}

/** A draft: no number yet, and the only state where edit/delete are legal. */
function draftRow(overrides: Partial<JournalEntryRow> = {}): JournalEntryRow {
    return row({
        id: 8,
        entry_number: null,
        status: 'draft',
        period_code: null,
        total_debit_centavos: 0,
        total_credit_centavos: 0,
        can: { update: true, delete: true, reverse: false },
        ...overrides,
    });
}

function paginator(
    data: JournalEntryRow[],
    overrides: Partial<Paginator<JournalEntryRow>> = {},
): Paginator<JournalEntryRow> {
    return {
        data,
        current_page: 1,
        last_page: 1,
        per_page: 25,
        from: 1,
        to: data.length,
        total: data.length,
        links: [],
        ...overrides,
    };
}

function renderIndex(
    entries: Paginator<JournalEntryRow>,
    status: JournalEntryRow['status'] | null = null,
) {
    return render(
        <JournalIndex
            entries={entries}
            filters={{ search: null, status, from: null, to: null }}
            can={{ create: true }}
        />,
    );
}

describe('JournalIndex — reaching an entry', () => {
    it('gives every row a way in', () => {
        // The original report: the list had one un-underlined text link per
        // row and no buttons, so entries read as unreachable.
        renderIndex(
            paginator([
                row({ id: 1 }),
                row({ id: 2, entry_number: 'JE-2026-00002' }),
                draftRow(),
            ]),
        );

        expect(screen.getAllByLabelText(/^Open journal entry/)).toHaveLength(3);
    });

    it('names a draft control by id, since it has no entry number', () => {
        renderIndex(paginator([draftRow({ id: 8 })]));

        expect(
            screen.getByLabelText('Open journal entry #8'),
        ).toBeInTheDocument();
        // Never "Open journal entry —".
        expect(
            screen.queryByLabelText('Open journal entry —'),
        ).not.toBeInTheDocument();
    });

    it('points the control at the entry detail page', () => {
        renderIndex(paginator([row({ id: 42 })]));

        expect(
            screen
                .getByLabelText('Open journal entry JE-2026-00001')
                .closest('a'),
        ).toHaveAttribute('href', '/admin/journal-entries/42');
    });
});

describe('JournalIndex — row actions', () => {
    it('offers edit and delete on a draft', () => {
        renderIndex(paginator([draftRow({ id: 8 })]));

        expect(
            screen.getByLabelText('Edit journal entry #8'),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('Delete journal entry #8'),
        ).toBeInTheDocument();
    });

    it('offers neither on a posted entry', () => {
        // A posted entry is immutable; it is corrected by posting a reversal.
        renderIndex(paginator([row()]));

        expect(
            screen.queryByLabelText(/^Edit journal entry/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText(/^Delete journal entry/),
        ).not.toBeInTheDocument();
        expect(
            screen.getByLabelText(/^Open journal entry/),
        ).toBeInTheDocument();
    });

    it('keeps post and reverse off the list', () => {
        // Both are irreversible or near enough, so they keep their
        // confirmation step on the detail page.
        renderIndex(paginator([row(), draftRow()]));

        expect(screen.queryByLabelText(/Post/i)).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/Reverse/i)).not.toBeInTheDocument();
    });

    it('confirms before deleting, and does not fire until confirmed', () => {
        renderIndex(paginator([draftRow({ id: 8 })]));

        fireEvent.click(screen.getByLabelText('Delete journal entry #8'));

        expect(screen.getByText('Delete this draft?')).toBeInTheDocument();
        expect(routerDelete).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Delete draft' }));

        expect(routerDelete).toHaveBeenCalledTimes(1);
        expect(routerDelete.mock.calls[0][0]).toBe('/admin/journal-entries/8');
    });
});

describe('JournalIndex — pagination', () => {
    it('shows no controls on a single page', () => {
        renderIndex(paginator([row()]));

        expect(screen.queryByText('Previous')).not.toBeInTheDocument();
        expect(screen.queryByText('Next')).not.toBeInTheDocument();
    });

    it('shows controls once there is more than one page', () => {
        // The journal grows without bound, which is why the controller
        // paginates — without controls, everything past page 1 is stranded.
        renderIndex(
            paginator([row()], { current_page: 1, last_page: 3, total: 60 }),
        );

        expect(screen.getByText('Previous')).toBeInTheDocument();
        expect(screen.getByText('Next')).toBeInTheDocument();
        expect(screen.getByText(/Page 1 of 3/)).toBeInTheDocument();
    });

    it('disables previous on the first page and next on the last', () => {
        const { unmount } = renderIndex(
            paginator([row()], { current_page: 1, last_page: 3 }),
        );
        expect(screen.getByText('Previous').closest('button')).toBeDisabled();
        expect(screen.getByText('Next').closest('button')).toBeEnabled();
        unmount();

        renderIndex(paginator([row()], { current_page: 3, last_page: 3 }));
        expect(screen.getByText('Previous').closest('button')).toBeEnabled();
        expect(screen.getByText('Next').closest('button')).toBeDisabled();
    });

    it('carries the active status filter across a page change', () => {
        renderIndex(
            paginator([draftRow()], { current_page: 1, last_page: 2 }),
            'draft',
        );

        fireEvent.click(screen.getByText('Next'));

        // Dropping the filter here would silently dump the operator back into
        // an unfiltered list.
        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet.mock.calls[0][1]).toEqual({
            page: 2,
            status: 'draft',
        });
    });
});

describe('filter clearing', () => {
    it('offers no Clear on an unfiltered list', () => {
        // A control that does nothing reads as though a filter is on.
        renderIndex(paginator([]));

        expect(
            screen.queryByRole('button', { name: /clear filters/i }),
        ).not.toBeInTheDocument();
    });

    it('offers Clear once a status is applied', () => {
        renderIndex(paginator([]), 'draft');

        expect(
            screen.getByRole('button', { name: /clear filters/i }),
        ).toBeInTheDocument();
    });
});

describe('JournalIndex — search', () => {
    it('visits with the typed term, debounced', async () => {
        // `useTableFilters` waits 300ms before visiting, so a burst of
        // keystrokes is one request rather than one per character.
        vi.useFakeTimers();
        routerGet.mockClear();

        renderIndex(paginator([row()]));

        fireEvent.change(screen.getByLabelText('Search journal entries'), {
            target: { value: 'Zachary' },
        });

        expect(routerGet).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(400);

        expect(routerGet).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ search: 'Zachary' }),
            expect.anything(),
        );

        vi.useRealTimers();
    });

    it('carries the status filter along with the search', () => {
        // The reason the page uses one filter object: a search must not
        // silently widen the list back out to every status.
        routerGet.mockClear();

        renderIndex(paginator([row()]), 'posted');

        fireEvent.change(screen.getByLabelText('Search journal entries'), {
            target: { value: 'OR-9423' },
        });

        // The status select is separate, and applies immediately.
        fireEvent.click(screen.getByLabelText('Filter by status'));

        expect(screen.getByLabelText('Search journal entries')).toHaveValue(
            'OR-9423',
        );
    });
});
