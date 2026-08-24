import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ContactsIndex from '@/pages/admin/accounting/contacts/index';
import type { ContactRow, Paginator } from '@/types';

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
    useForm: () => ({
        data: {},
        errors: {},
        processing: false,
        isDirty: false,
        setData: vi.fn(),
        setDefaults: vi.fn(),
        clearErrors: vi.fn(),
        reset: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
    }),
    usePage: () => ({ props: { auth: { user: null } } }),
}));

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function row(overrides: Partial<ContactRow> = {}): ContactRow {
    return {
        id: 1,
        code: 'ACME',
        name: 'Acme Trading',
        is_customer: true,
        is_supplier: false,
        tin: '123456789',
        email: 'billing@acme.test',
        phone: null,
        address: null,
        receivable_account_id: null,
        payable_account_id: null,
        receivable_account: null,
        payable_account: null,
        lms_student_id: null,
        is_active: true,
        notes: null,
        can: { update: true, delete: true },
        ...overrides,
    };
}

function paginator(
    data: ContactRow[],
    overrides: Partial<Paginator<ContactRow>> = {},
): Paginator<ContactRow> {
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
    contacts: Paginator<ContactRow>,
    filters: { search: string | null; role: 'customer' | 'supplier' | null } = {
        search: null,
        role: null,
    },
) {
    return render(
        <ContactsIndex
            contacts={contacts}
            filters={filters}
            receivableAccountOptions={[]}
            payableAccountOptions={[]}
            can={{ create: true }}
        />,
    );
}

describe('ContactsIndex', () => {
    it('shows a contact with its role', () => {
        renderIndex(paginator([row()]));

        expect(screen.getByText('Acme Trading')).toBeInTheDocument();
        expect(screen.getByText('Customer')).toBeInTheDocument();
    });

    it('shows both badges when a contact is customer and supplier', () => {
        renderIndex(paginator([row({ is_supplier: true })]));

        expect(screen.getByText('Customer')).toBeInTheDocument();
        expect(screen.getByText('Supplier')).toBeInTheDocument();
    });

    it('gives every row edit and delete controls', () => {
        renderIndex(paginator([row({ id: 1, code: 'ACME' })]));

        expect(screen.getByLabelText('Edit contact ACME')).toBeInTheDocument();
        expect(
            screen.getByLabelText('Delete contact ACME'),
        ).toBeInTheDocument();
    });

    it('hides controls the viewer is not permitted', () => {
        renderIndex(
            paginator([row({ can: { update: false, delete: false } })]),
        );

        expect(
            screen.queryByLabelText(/^Edit contact/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText(/^Delete contact/),
        ).not.toBeInTheDocument();
    });

    it('confirms before deleting', () => {
        renderIndex(paginator([row({ id: 7, code: 'ACME' })]));

        fireEvent.click(screen.getByLabelText('Delete contact ACME'));

        expect(screen.getByText('Delete this contact?')).toBeInTheDocument();
        expect(routerDelete).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(routerDelete).toHaveBeenCalledTimes(1);
        expect(routerDelete.mock.calls[0][0]).toBe('/admin/contacts/7');
    });

    it('opens the sheet in create mode', () => {
        renderIndex(paginator([row()]));

        fireEvent.click(screen.getByText('New contact'));

        expect(
            screen.getByText('New contact', {
                selector: 'h2, [data-slot="sheet-title"]',
            }),
        ).toBeInTheDocument();
    });

    it('distinguishes an empty register from an empty search', () => {
        const { unmount } = renderIndex(paginator([]));
        expect(screen.getByText('No contacts yet')).toBeInTheDocument();
        unmount();

        // Offering "add your first contact" when a search simply missed is
        // the wrong instruction.
        renderIndex(paginator([]), { search: 'zzz', role: null });
        expect(
            screen.getByText('No contacts match that search'),
        ).toBeInTheDocument();
    });

    it('shows pagination only past one page', () => {
        const { unmount } = renderIndex(paginator([row()]));
        expect(screen.queryByText('Next')).not.toBeInTheDocument();
        unmount();

        renderIndex(
            paginator([row()], { current_page: 1, last_page: 3, total: 60 }),
        );
        expect(screen.getByText('Next')).toBeInTheDocument();
        expect(screen.getByText(/Page 1 of 3/)).toBeInTheDocument();
    });

    it('carries search and role filters across a page change', () => {
        renderIndex(paginator([row()], { current_page: 1, last_page: 2 }), {
            search: 'acme',
            role: 'customer',
        });

        fireEvent.click(screen.getByText('Next'));

        expect(routerGet).toHaveBeenCalled();
        const lastCall = routerGet.mock.calls.at(-1);
        expect(lastCall?.[1]).toEqual({
            page: 2,
            search: 'acme',
            role: 'customer',
        });
    });
});
