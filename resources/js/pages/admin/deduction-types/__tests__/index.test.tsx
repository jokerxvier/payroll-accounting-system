import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DeductionTypesIndex from '@/pages/admin/deduction-types/index';
import type { DeductionTypeRow } from '@/types';

// Inertia bindings are not exercised in the test environment — the test
// asserts presentational behavior only. Stub Link to a plain <a>, Head to
// null, and router.delete to a no-op so the component mounts cleanly.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        delete: vi.fn(),
    },
    usePage: () => ({ props: { auth: { user: null } } }),
}));

// Sonner pulls in Radix portals that pollute the JSDOM tree; stub it.
vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

function makeRow(overrides: Partial<DeductionTypeRow> = {}): DeductionTypeRow {
    return {
        id: 1,
        code: 'HMO',
        name: 'HMO premium',
        is_taxable: false,
        calc_method: 'fixed',
        source: 'employee',
        is_active: true,
        notes: 'Standard HMO deduction',
        ...overrides,
    };
}

describe('DeductionTypesIndex', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the empty state when no deduction types exist', () => {
        render(<DeductionTypesIndex deductionTypes={[]} />);

        expect(screen.getByText('No deduction types yet')).toBeInTheDocument();
        // Multiple "New deduction type" CTAs (header + empty state action)
        expect(
            screen.getAllByRole('link', { name: /new deduction type/i }).length,
        ).toBeGreaterThanOrEqual(2);
    });

    it('renders rows with all status badges and the truncated notes column', () => {
        const rows: DeductionTypeRow[] = [
            makeRow({
                id: 1,
                code: 'HMO',
                name: 'HMO premium',
                is_taxable: false,
                calc_method: 'fixed',
                source: 'employee',
                is_active: true,
            }),
            makeRow({
                id: 2,
                code: 'COOP',
                name: 'Cooperative savings',
                is_taxable: true,
                calc_method: 'percent',
                source: 'both',
                is_active: false,
            }),
        ];

        render(<DeductionTypesIndex deductionTypes={rows} />);

        // Locate the two body rows by their accessible row name.
        const hmoRow = screen.getByRole('row', { name: /HMO premium/i });
        const coopRow = screen.getByRole('row', {
            name: /Cooperative savings/i,
        });

        // First row — fixed / employee / non-taxable / active
        expect(within(hmoRow).getByText('HMO')).toBeInTheDocument();
        expect(within(hmoRow).getByText('Fixed')).toBeInTheDocument();
        expect(within(hmoRow).getByText('Employee')).toBeInTheDocument();
        expect(within(hmoRow).getByText('Non-taxable')).toBeInTheDocument();
        expect(within(hmoRow).getByText('Active')).toBeInTheDocument();

        // Second row — percent / both / taxable / inactive
        expect(within(coopRow).getByText('COOP')).toBeInTheDocument();
        expect(within(coopRow).getByText('Percent')).toBeInTheDocument();
        expect(within(coopRow).getByText('Both')).toBeInTheDocument();
        expect(within(coopRow).getByText('Taxable')).toBeInTheDocument();
        expect(within(coopRow).getByText('Inactive')).toBeInTheDocument();
    });

    it('opens the confirmation dialog when delete is clicked', async () => {
        const rows = [makeRow({ code: 'UNIFORM', name: 'Uniform fee' })];

        render(<DeductionTypesIndex deductionTypes={rows} />);

        // Dialog content is not in the DOM until trigger is fired
        expect(
            screen.queryByText('Delete this deduction type?'),
        ).not.toBeInTheDocument();

        const deleteButton = screen.getByRole('button', {
            name: /delete uniform/i,
        });
        fireEvent.click(deleteButton);

        const dialog = await screen.findByRole('alertdialog');
        expect(dialog).toBeInTheDocument();
        expect(
            within(dialog).getByText('Delete this deduction type?'),
        ).toBeInTheDocument();
        // The dialog body references the row's identity
        expect(within(dialog).getByText(/UNIFORM/)).toBeInTheDocument();
        // Cancel + Delete actions present
        expect(
            within(dialog).getByRole('button', { name: 'Cancel' }),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByRole('button', { name: 'Delete' }),
        ).toBeInTheDocument();
    });
});
