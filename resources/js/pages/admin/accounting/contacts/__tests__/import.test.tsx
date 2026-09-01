import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ContactImportPage from '@/pages/admin/accounting/contacts/import';
import type {
    ContactImportPageProps,
    ContactImportRow,
    ContactImportSummary,
} from '@/types/contact-import';

/*
 * The import preview.
 *
 * Its whole job is to answer "what will this file do to my register" before
 * the answer becomes permanent, so the cases that matter are the ones where
 * an operator would otherwise be guessing: which fields actually move, which
 * rows are new, and why the button is refusing to do anything.
 */

let uploadErrors: Record<string, string> = {};
let confirmErrors: Record<string, string> = {};

beforeEach(() => {
    uploadErrors = {};
    confirmErrors = {};
});

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    // href passed through: an <a> without one has no `link` role, so a mock
    // that swallows it makes every destination assertion impossible.
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        clearErrors: vi.fn(),
        processing: false,
        // The page runs two forms and Inertia keeps their errors apart; one
        // shared object could not catch a confirm refusal going unrendered.
        errors: 'file' in initial ? uploadErrors : confirmErrors,
    }),
}));

function row(overrides: Partial<ContactImportRow> = {}): ContactImportRow {
    return {
        row_number: 2,
        code: 'C-0001',
        contact_id: 7,
        name: 'Dela Cruz Family',
        action: 'unchanged',
        changes: {},
        errors: [],
        ...overrides,
    };
}

function summary(
    overrides: Partial<ContactImportSummary> = {},
): ContactImportSummary {
    return {
        row_count: 1,
        create_count: 0,
        update_count: 0,
        unchanged_count: 1,
        error_count: 0,
        ...overrides,
    };
}

function props(
    overrides: Partial<ContactImportPageProps> = {},
): ContactImportPageProps {
    return {
        parsed: [row()],
        token: 'tok',
        sourceFilename: 'contacts.xlsx',
        summary: summary(),
        ...overrides,
    };
}

function applyButton(): HTMLButtonElement {
    return screen.getByRole('button', {
        name: /apply these changes/i,
    }) as HTMLButtonElement;
}

describe('getting out again', () => {
    it('offers a way back before a file has been uploaded', () => {
        // The Apply/Cancel pair only appears once there is a preview, so
        // without this the page is a dead end for anyone who opened it by
        // mistake.
        render(<ContactImportPage />);

        expect(
            screen.getByRole('link', { name: /back to contacts/i }),
        ).toHaveAttribute('href', '/admin/contacts');
    });

    it('still offers it while a preview is on screen', () => {
        render(<ContactImportPage {...props()} />);

        expect(
            screen.getByRole('link', { name: /back to contacts/i }),
        ).toBeInTheDocument();
    });
});

describe('before a file is uploaded', () => {
    it('offers both an export and an empty template', () => {
        render(<ContactImportPage />);

        expect(
            screen.getByRole('link', { name: /export contacts/i }),
        ).toHaveAttribute('href', '/admin/contacts/export');
        expect(
            screen.getByRole('link', { name: /download template/i }),
        ).toHaveAttribute('href', '/admin/contacts/import/template');
    });

    it('warns that the code column is the join key', () => {
        // The one way to damage the register with this feature: editing a
        // code splits a contact in two instead of renaming it.
        render(<ContactImportPage />);

        expect(
            screen.getByText(/does not rename a contact/i),
        ).toBeInTheDocument();
    });
});

describe('the preview', () => {
    it('names each field that moves, from and to', () => {
        render(
            <ContactImportPage
                {...props({
                    parsed: [
                        row({
                            action: 'update',
                            changes: {
                                email: {
                                    from: 'old@example.com',
                                    to: 'new@example.com',
                                },
                            },
                        }),
                    ],
                    summary: summary({
                        update_count: 1,
                        unchanged_count: 0,
                    }),
                })}
            />,
        );

        const line = screen.getByText('Dela Cruz Family').closest('tr');

        expect(line).not.toBeNull();
        expect(
            within(line as HTMLElement).getByText('Email:'),
        ).toBeInTheDocument();
        expect(
            within(line as HTMLElement).getByText('old@example.com'),
        ).toBeInTheDocument();
        expect(
            within(line as HTMLElement).getByText('new@example.com'),
        ).toBeInTheDocument();
        expect(applyButton()).not.toBeDisabled();
    });

    it('reads an empty value as empty rather than blank space', () => {
        render(
            <ContactImportPage
                {...props({
                    parsed: [
                        row({
                            action: 'update',
                            changes: { phone: { from: null, to: '0917' } },
                        }),
                    ],
                    summary: summary({ update_count: 1, unchanged_count: 0 }),
                })}
            />,
        );

        expect(screen.getByText('(empty)')).toBeInTheDocument();
    });

    it('marks a row the file will create', () => {
        render(
            <ContactImportPage
                {...props({
                    parsed: [row({ action: 'create', contact_id: null })],
                    summary: summary({ create_count: 1, unchanged_count: 0 }),
                })}
            />,
        );

        expect(screen.getByText('New contact')).toBeInTheDocument();
        expect(applyButton()).not.toBeDisabled();
    });

    it('blocks the import while a row is wrong, and says why', () => {
        render(
            <ContactImportPage
                {...props({
                    parsed: [
                        row({
                            action: 'create',
                            errors: ['name is required.'],
                        }),
                    ],
                    summary: summary({
                        create_count: 1,
                        unchanged_count: 0,
                        error_count: 1,
                    }),
                })}
            />,
        );

        expect(screen.getByText('name is required.')).toBeInTheDocument();
        expect(screen.getByText(/1 problem to fix/i)).toBeInTheDocument();
        expect(applyButton()).toBeDisabled();
    });

    it('says so when the file would change nothing', () => {
        // The normal outcome of a round trip nobody edited. Without this the
        // disabled button reads as broken.
        render(<ContactImportPage {...props()} />);

        expect(
            screen.getByText(/this file changes nothing/i),
        ).toBeInTheDocument();
        expect(applyButton()).toBeDisabled();
    });
});

describe('a refusal from the confirm endpoint', () => {
    it('shows why nothing was imported', () => {
        confirmErrors = { token: 'Preview is no longer valid.' };

        render(<ContactImportPage {...props()} />);

        expect(
            screen.getByText(/preview is no longer valid/i),
        ).toBeInTheDocument();
    });
});
