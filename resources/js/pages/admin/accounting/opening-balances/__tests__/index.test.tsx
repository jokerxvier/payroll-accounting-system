import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import OpeningBalancesIndex from '@/pages/admin/accounting/opening-balances';
import type {
    OpeningBalanceRow,
    OpeningBalanceSummary,
} from '@/types/opening-balance';

/*
 * Errors the SERVER hands back, per form.
 *
 * The page runs two `useForm` instances and Inertia keeps their errors
 * apart — a failed confirm never populates the upload form's errors. Any
 * mock returning one shared `errors: {}` cannot tell the two apart, and so
 * cannot catch a confirm refusal being dropped on the floor.
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
        processing: false,
        errors:
            'plug_to_retained_earnings' in initial
                ? confirmErrors
                : uploadErrors,
    }),
}));

function row(overrides: Partial<OpeningBalanceRow> = {}): OpeningBalanceRow {
    return {
        row_number: 2,
        account_code: '1100',
        account_id: 1,
        account_name: 'Cash on Hand',
        account_type: 'asset',
        debit_centavos: 700_000_00,
        credit_centavos: 0,
        errors: [],
        ...overrides,
    };
}

function summary(
    overrides: Partial<OpeningBalanceSummary> = {},
): OpeningBalanceSummary {
    return {
        total_debit_centavos: 700_000_00,
        total_credit_centavos: 700_000_00,
        difference_centavos: 0,
        row_count: 2,
        error_count: 0,
        period_is_open: true,
        ...overrides,
    };
}

const balancedProps = {
    parsed: [
        row(),
        row({
            row_number: 3,
            account_code: '3200',
            account_id: 2,
            account_name: 'Retained Earnings',
            account_type: 'equity',
            debit_centavos: 0,
            credit_centavos: 700_000_00,
        }),
    ],
    token: 'tok',
    sourceFilename: 'opening-balances.xlsx',
    cutoverDate: '2026-06-30',
    summary: summary(),
};

function confirmButton(): HTMLButtonElement {
    return screen.getByRole('button', {
        name: /post opening balances/i,
    }) as HTMLButtonElement;
}

describe('opening balances import', () => {
    it('offers only the upload steps before a worksheet is parsed', () => {
        render(<OpeningBalancesIndex />);

        expect(screen.getByText(/download template/i)).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /post opening balances/i }),
        ).not.toBeInTheDocument();
    });

    it('reports a balanced sheet and allows the post', () => {
        render(<OpeningBalancesIndex {...balancedProps} />);

        expect(screen.getByText('Balanced')).toBeInTheDocument();
        expect(confirmButton()).not.toBeDisabled();
    });

    it('names the difference and blocks the post when out of balance', () => {
        render(
            <OpeningBalancesIndex
                {...balancedProps}
                summary={summary({
                    total_credit_centavos: 650_000_00,
                    difference_centavos: 50_000_00,
                })}
            />,
        );

        expect(screen.getByText('Out of balance')).toBeInTheDocument();
        // Scoped to the sentence rather than searched for globally: the
        // formatted figure "50,000.00" is also a substring of the
        // "650,000.00" credit total, so a bare text query matches twice.
        expect(
            screen.getByText(/debits exceed credits by/i).textContent,
        ).toContain('50,000.00');
        // The plug is offered, but unticked — so the post stays blocked.
        expect(
            screen.getByText(/post the difference to retained earnings/i),
        ).toBeInTheDocument();
        expect(confirmButton()).toBeDisabled();
    });

    it('blocks the post while any row carries an error', () => {
        render(
            <OpeningBalancesIndex
                {...balancedProps}
                parsed={[
                    row({
                        account_code: '4100',
                        account_name: 'Tuition Fee Income',
                        errors: [
                            '4100 is an income account. Income and expense accounts close out at year end.',
                        ],
                    }),
                ]}
                summary={summary({ error_count: 1 })}
            />,
        );

        expect(screen.getByText(/1 row need fixing/i)).toBeInTheDocument();
        expect(screen.getByText(/income account/i)).toBeInTheDocument();
        expect(confirmButton()).toBeDisabled();
        // An errored sheet is not an unbalanced one — the plug must not be
        // offered as a way past a bad row.
        expect(
            screen.queryByText(/post the difference to retained earnings/i),
        ).not.toBeInTheDocument();
    });

    it('blocks the post when no open period covers the cutover date', () => {
        render(
            <OpeningBalancesIndex
                {...balancedProps}
                summary={summary({ period_is_open: false })}
            />,
        );

        expect(
            screen.getByText(/no open period covers 2026-06-30/i),
        ).toBeInTheDocument();
        expect(confirmButton()).toBeDisabled();
    });

    it('points at the standing snapshot instead of letting a second one through', () => {
        render(
            <OpeningBalancesIndex
                existingSnapshot={{
                    id: 7,
                    entry_number: 'JE-2026-00042',
                    date: '2026-06-30',
                }}
            />,
        );

        expect(
            screen.getByText(/these books are already open/i),
        ).toBeInTheDocument();
        expect(screen.getByText('JE-2026-00042')).toBeInTheDocument();
    });
});

/*
 * Refusals that only the confirm endpoint can raise.
 *
 * The preview catches what it can see, but three things it cannot: the
 * session expiring, another tab opening the books first, and a sheet whose
 * rows are all zero. Each comes back from `OpeningBalanceController::confirm`
 * as a redirect carrying `errors.file` or `errors.token` — and a redirect
 * that renders identically to the page you were already on reads as a dead
 * button, which is exactly what was reported.
 */
describe('a refusal from the confirm endpoint', () => {
    it('shows why the post was refused', () => {
        confirmErrors = {
            file: 'An opening balance needs at least one account with a non-zero figure.',
        };

        render(<OpeningBalancesIndex {...balancedProps} />);

        expect(
            screen.getByText(/at least one account with a non-zero figure/i),
        ).toBeInTheDocument();
    });

    it('shows an expired preview, which is keyed differently', () => {
        confirmErrors = {
            token: 'Preview is no longer valid. Re-upload the worksheet.',
        };

        render(<OpeningBalancesIndex {...balancedProps} />);

        expect(
            screen.getByText(/preview is no longer valid/i),
        ).toBeInTheDocument();
    });

    it('says nothing when the post has not been refused', () => {
        render(<OpeningBalancesIndex {...balancedProps} />);

        expect(
            screen.queryByRole('alert', { name: /could not be posted/i }),
        ).not.toBeInTheDocument();
    });
});
