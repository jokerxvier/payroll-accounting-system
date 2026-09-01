import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import OpeningItemsIndex from '@/pages/admin/accounting/opening-items';
import type {
    OpeningItemPageProps,
    OpeningItemRow,
    OpeningItemSummary,
} from '@/types/opening-item';

/*
 * Errors the SERVER hands back, per form.
 *
 * The page runs two `useForm` instances and Inertia keeps their errors apart —
 * a failed confirm never populates the upload form's. A mock with one shared
 * `errors: {}` cannot tell them apart, and so cannot catch a confirm refusal
 * being dropped on the floor.
 */
let uploadErrors: Record<string, string> = {};
let confirmErrors: Record<string, string> = {};

beforeEach(() => {
    uploadErrors = {};
    confirmErrors = {};
});

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        clearErrors: vi.fn(),
        processing: false,
        errors: 'file' in initial ? uploadErrors : confirmErrors,
    }),
}));

function row(overrides: Partial<OpeningItemRow> = {}): OpeningItemRow {
    return {
        row_number: 2,
        type: 'sales',
        contact_id: 7,
        contact_name: 'Dela Cruz Family',
        number: 'OLD-0042',
        issue_date: '2026-05-31',
        due_date: '2026-06-15',
        total_centavos: 500_000,
        amount_paid_centavos: 0,
        student_name: 'Juan Dela Cruz',
        warnings: [],
        errors: [],
        ...overrides,
    };
}

function summary(
    overrides: Partial<OpeningItemSummary> = {},
): OpeningItemSummary {
    return {
        row_count: 1,
        error_count: 0,
        warning_count: 0,
        total_centavos: 500_000,
        already_paid_centavos: 0,
        outstanding_centavos: 500_000,
        books_are_open: true,
        ...overrides,
    };
}

const previewProps: OpeningItemPageProps = {
    parsed: [row()],
    token: 'tok',
    sourceFilename: 'opening-items.xlsx',
    booksOpenedOn: '2026-06-30',
    summary: summary(),
    reconciliation: [
        {
            key: 'receivable',
            label: 'Receivables',
            control_centavos: 500_000,
            items_centavos: 500_000,
            difference_centavos: 0,
            is_reconciled: true,
        },
    ],
    recordedCount: 0,
};

function confirmButton(): HTMLButtonElement {
    return screen.getByRole('button', {
        name: /record these open items/i,
    }) as HTMLButtonElement;
}

describe('before the books are open', () => {
    it('sends the user to opening balances first', () => {
        render(<OpeningItemsIndex booksOpenedOn={null} />);

        expect(screen.getByText(/open the books first/i)).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /record these open items/i }),
        ).not.toBeInTheDocument();
    });
});

describe('the reconciliation panel', () => {
    it('says the sub-ledger ties', () => {
        render(<OpeningItemsIndex {...previewProps} />);

        expect(
            screen.getByText(/receivables tie to the opening balance/i),
        ).toBeInTheDocument();
        expect(confirmButton()).not.toBeDisabled();
    });

    it('names the difference but still allows the import', () => {
        // The decision this page turns on: a gap is a finding about the books
        // being migrated FROM, not a reason to refuse the migration.
        render(
            <OpeningItemsIndex
                {...previewProps}
                reconciliation={[
                    {
                        key: 'receivable',
                        label: 'Receivables',
                        control_centavos: 500_000,
                        items_centavos: 320_000,
                        difference_centavos: 180_000,
                        is_reconciled: false,
                    },
                ]}
            />,
        );

        expect(
            screen.getByText(/do not tie to the opening balance/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/off by/i).textContent).toContain('1,800.00');
        expect(confirmButton()).not.toBeDisabled();
    });
});

describe('the preview table', () => {
    it('shows what each document will bring in', () => {
        render(<OpeningItemsIndex {...previewProps} />);

        const line = screen.getByText('OLD-0042').closest('tr');

        expect(line).not.toBeNull();
        expect(
            within(line as HTMLElement).getByText('Dela Cruz Family'),
        ).toBeInTheDocument();
        expect(
            within(line as HTMLElement).getByText('Juan Dela Cruz'),
        ).toBeInTheDocument();
    });

    it('says so plainly when no due date was given', () => {
        // It lands in Current rather than Overdue, so the wording must not
        // read as a missing value the user has to go and fix.
        render(
            <OpeningItemsIndex
                {...previewProps}
                parsed={[row({ due_date: null })]}
            />,
        );

        expect(screen.getByText('No due date')).toBeInTheDocument();
    });

    it('blocks the import while a row is wrong, and says why', () => {
        render(
            <OpeningItemsIndex
                {...previewProps}
                parsed={[
                    row({
                        errors: ['No contact named "Nobody At All".'],
                    }),
                ]}
                summary={summary({ error_count: 1 })}
            />,
        );

        expect(screen.getByText(/1 row need fixing/i)).toBeInTheDocument();
        expect(screen.getByText(/no contact named/i)).toBeInTheDocument();
        expect(confirmButton()).toBeDisabled();
    });

    it('shows a warning without blocking', () => {
        render(
            <OpeningItemsIndex
                {...previewProps}
                parsed={[
                    row({
                        number: 'INV-2025-00042',
                        warnings: [
                            "INV-2025-00042 matches this system's own numbering, so the next invoice of that year will continue after it.",
                        ],
                    }),
                ]}
                summary={summary({ warning_count: 1 })}
            />,
        );

        expect(
            screen.getByText(/matches this system's own numbering/i),
        ).toBeInTheDocument();
        expect(confirmButton()).not.toBeDisabled();
    });
});

describe('a refusal from the confirm endpoint', () => {
    it('shows why nothing was recorded', () => {
        confirmErrors = {
            file: 'Open items are already recorded.',
        };

        render(<OpeningItemsIndex {...previewProps} />);

        expect(
            screen.getByText(/open items are already recorded\./i),
        ).toBeInTheDocument();
    });
});

describe('a second import', () => {
    it('points at the standing set rather than letting one through', () => {
        render(
            <OpeningItemsIndex booksOpenedOn="2026-06-30" recordedCount={12} />,
        );

        expect(
            screen.getByText(/open items are already recorded/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/12 documents/i)).toBeInTheDocument();
    });
});
