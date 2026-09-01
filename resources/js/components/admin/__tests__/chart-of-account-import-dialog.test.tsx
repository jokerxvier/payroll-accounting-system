import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChartOfAccountImportDialog } from '@/components/admin/chart-of-account-import-dialog';
import type {
    ChartImportPreview,
    ChartImportRow,
    ChartImportSummary,
} from '@/types/chart-of-account-import';

/*
 * The chart import dialog.
 *
 * Its whole job is to say what a file will do to the chart before the answer
 * becomes permanent, so what needs covering is the moments an operator would
 * otherwise be guessing: which fields move, which rows are new, and why the
 * button will not press.
 */

let uploadErrors: Record<string, string> = {};
let confirmErrors: Record<string, string> = {};

beforeEach(() => {
    uploadErrors = {};
    confirmErrors = {};
});

vi.mock('@inertiajs/react', () => ({
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        clearErrors: vi.fn(),
        processing: false,
        // Two forms, and Inertia keeps their errors apart; one shared object
        // could not catch a confirm refusal going unrendered.
        errors: 'file' in initial ? uploadErrors : confirmErrors,
    }),
}));

function row(overrides: Partial<ChartImportRow> = {}): ChartImportRow {
    return {
        row_number: 2,
        code: '1100',
        account_id: 3,
        name: 'Cash on Hand',
        action: 'unchanged',
        parent_code: null,
        changes: {},
        errors: [],
        ...overrides,
    };
}

function summary(
    overrides: Partial<ChartImportSummary> = {},
): ChartImportSummary {
    return {
        row_count: 1,
        create_count: 0,
        update_count: 0,
        unchanged_count: 1,
        error_count: 0,
        ...overrides,
    };
}

function preview(
    overrides: Partial<ChartImportPreview> = {},
): ChartImportPreview {
    return {
        parsed: [row()],
        token: 'tok',
        sourceFilename: 'chart-of-accounts.xlsx',
        summary: summary(),
        ...overrides,
    };
}

function open(value?: ChartImportPreview | null) {
    render(
        <ChartOfAccountImportDialog
            open
            onOpenChange={vi.fn()}
            preview={value}
        />,
    );
}

function applyButton(): HTMLButtonElement {
    return screen.getByRole('button', {
        name: /apply these changes/i,
    }) as HTMLButtonElement;
}

describe('before a file is chosen', () => {
    it('offers the export and the template from inside the dialog', () => {
        // The point of the modal: everything needed is here, without leaving
        // the chart it applies to.
        open(null);

        expect(
            screen.getByRole('link', { name: /export current chart/i }),
        ).toHaveAttribute('href', '/admin/chart-of-accounts/export');
        expect(
            screen.getByRole('link', { name: /download template/i }),
        ).toHaveAttribute('href', '/admin/chart-of-accounts/import/template');
    });

    it('warns that the code column is the join key', () => {
        open(null);

        expect(
            screen.getByText(/does not renumber an account/i),
        ).toBeInTheDocument();
    });

    it('keeps Apply disabled with nothing to apply', () => {
        open(null);

        expect(applyButton()).toBeDisabled();
    });
});

describe('the preview', () => {
    it('names each field that moves, from and to', () => {
        // `name` on the row is what the SHEET says, so a rename shows the new
        // name in the header and the old one struck through in the diff.
        open(
            preview({
                parsed: [
                    row({
                        name: 'Petty Cash',
                        action: 'update',
                        changes: {
                            name: { from: 'Cash on Hand', to: 'Petty Cash' },
                        },
                    }),
                ],
                summary: summary({ update_count: 1, unchanged_count: 0 }),
            }),
        );

        expect(screen.getByText('Name:')).toBeInTheDocument();
        // Once, in the diff: the value being replaced.
        expect(screen.getByText('Cash on Hand')).toBeInTheDocument();
        // Twice — the row heading and the diff's new value.
        expect(screen.getAllByText('Petty Cash')).toHaveLength(2);
        expect(applyButton()).not.toBeDisabled();
    });

    it('shows the normal balance moving with the type', () => {
        // It is never read from the sheet — it follows `type`. Surfacing it
        // stops a type change looking like it did one thing when it did two.
        open(
            preview({
                parsed: [
                    row({
                        action: 'update',
                        changes: {
                            type: { from: 'asset', to: 'expense' },
                            normal_balance: { from: 'debit', to: 'credit' },
                        },
                    }),
                ],
                summary: summary({ update_count: 1, unchanged_count: 0 }),
            }),
        );

        expect(screen.getByText('Normal balance:')).toBeInTheDocument();
    });

    it('marks a row the file will create', () => {
        open(
            preview({
                parsed: [row({ action: 'create', account_id: null })],
                summary: summary({ create_count: 1, unchanged_count: 0 }),
            }),
        );

        expect(screen.getByText('New')).toBeInTheDocument();
        expect(applyButton()).not.toBeDisabled();
    });

    it('blocks the import while a row is wrong, and says why', () => {
        open(
            preview({
                parsed: [
                    row({
                        action: 'create',
                        errors: [
                            'Only an asset account can be a cash equivalent.',
                        ],
                    }),
                ],
                summary: summary({
                    create_count: 1,
                    unchanged_count: 0,
                    error_count: 1,
                }),
            }),
        );

        expect(
            screen.getByText(/only an asset account can be a cash equivalent/i),
        ).toBeInTheDocument();
        expect(applyButton()).toBeDisabled();
    });

    it('says so when the file would change nothing', () => {
        // The normal result of a round trip nobody edited. Without this the
        // disabled button reads as broken.
        open(preview());

        expect(
            screen.getByText(/this file changes nothing/i),
        ).toBeInTheDocument();
        expect(applyButton()).toBeDisabled();
    });
});

describe('a refusal from the confirm endpoint', () => {
    it('shows why nothing was imported', () => {
        confirmErrors = { token: 'Preview is no longer valid.' };

        open(preview());

        expect(
            screen.getByText(/preview is no longer valid/i),
        ).toBeInTheDocument();
    });
});
