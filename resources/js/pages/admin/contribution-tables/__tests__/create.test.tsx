import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ContributionTablesCreate from '@/pages/admin/contribution-tables/create';
import { STATUTORY_CODE_PATTERN } from '@/types/statutory-contribution';

// Track the last router.post call so we can assert the submitted payload.
const routerPost = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        post: (...args: unknown[]) => routerPost(...args),
    },
    usePage: () => ({ props: { auth: { user: null } } }),
}));

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

const RECOMMENDED = [
    'BIR_WITHHOLDING',
    'SSS',
    'PHILHEALTH',
    'PAGIBIG',
    'CITY_TAX',
];

const ALGORITHMS = [
    'bracket_table',
    'salary_band',
    'percentage_with_cap',
    'tiered_percentage',
    'flat_percentage',
];

function renderPage(
    extra: Partial<React.ComponentProps<typeof ContributionTablesCreate>> = {},
) {
    return render(
        <ContributionTablesCreate
            recommendedCodes={RECOMMENDED}
            algorithmOptions={ALGORITHMS}
            {...extra}
        />,
    );
}

function getCodeInput(): HTMLInputElement {
    return screen.getByLabelText(/^Contribution code$/i) as HTMLInputElement;
}

describe('ContributionTablesCreate — contribution_code free-text input', () => {
    beforeEach(() => {
        routerPost.mockReset();
    });

    it('renders a text Input (not a Select) for contribution_code', () => {
        renderPage();

        const codeInput = getCodeInput();

        // It must be a real <input>, not a Radix Select trigger (button[role=combobox]).
        expect(codeInput.tagName).toBe('INPUT');
        expect(codeInput).toHaveAttribute('id', 'contribution_code');
    });

    it('renders a quick-pick chip for every recommended code, and clicking a chip fills the input', () => {
        renderPage();

        // One quick-pick button per recommended code.
        for (const code of RECOMMENDED) {
            const chip = screen.getByRole('button', { name: code });
            expect(chip).toBeInTheDocument();
        }

        const sssChip = screen.getByRole('button', { name: 'SSS' });
        fireEvent.click(sssChip);

        expect(getCodeInput()).toHaveValue('SSS');
    });

    it('auto-uppercases lowercase typed input', () => {
        renderPage();

        const codeInput = getCodeInput();
        fireEvent.change(codeInput, { target: { value: 'makati_tax' } });

        expect(codeInput).toHaveValue('MAKATI_TAX');
    });

    it('exposes the regex pattern attribute matching the spec', () => {
        renderPage();

        const codeInput = getCodeInput();
        const pattern = codeInput.getAttribute('pattern');

        expect(pattern).toBe(STATUTORY_CODE_PATTERN.source);
        // Sanity-check the regex itself accepts and rejects the right things.
        expect(STATUTORY_CODE_PATTERN.test('MAKATI_TAX')).toBe(true);
        expect(STATUTORY_CODE_PATTERN.test('CITY_TAX')).toBe(true);
        expect(STATUTORY_CODE_PATTERN.test('ab')).toBe(false); // too short / lowercase
        expect(STATUTORY_CODE_PATTERN.test('1ABC')).toBe(false); // must start with letter
    });

    it('passes a custom typed code through to router.post unchanged', () => {
        renderPage();

        const codeInput = getCodeInput();
        fireEvent.change(codeInput, { target: { value: 'MAKATI_TAX' } });

        // Submit the form. We don't need the rules subform to be valid — the
        // server is the source of truth — we just need to observe the payload.
        const form = codeInput.closest('form');
        expect(form).not.toBeNull();
        fireEvent.submit(form as HTMLFormElement);

        expect(routerPost).toHaveBeenCalledTimes(1);
        const [, payload] = routerPost.mock.calls[0] as [
            string,
            Record<string, unknown>,
        ];
        expect(payload.contribution_code).toBe('MAKATI_TAX');
    });
});

describe('ContributionTablesCreate — clone source prefill', () => {
    beforeEach(() => {
        routerPost.mockReset();
    });

    it('prefills algorithm, applies_to, application_order, label, notes, and rules from cloneSource', () => {
        const cloneSource = {
            id: 7,
            contribution_code: 'CITY_TAX',
            label: 'Quezon City local tax',
            algorithm: 'flat_percentage',
            application_order: 50,
            applies_to: 'gross_pay' as const,
            rules: {
                rate_bp: 150,
                employee_share_bp: 10000,
                employer_share_bp: 0,
                cap: null,
            },
            notes: 'Per QC Ordinance 2099-001.',
        };

        renderPage({ cloneSource });

        // Code is prefilled (admin can override).
        expect(getCodeInput()).toHaveValue('CITY_TAX');

        // Label is prefilled.
        const labelInput = screen.getByLabelText(
            /^Label$/i,
        ) as HTMLInputElement;
        expect(labelInput.value).toBe('Quezon City local tax');

        // Application order copied across.
        const orderInput = screen.getByLabelText(
            /^Application order$/i,
        ) as HTMLInputElement;
        expect(orderInput.value).toBe('50');

        // Notes copied across.
        const notesInput = screen.getByLabelText(
            /^Notes$/i,
        ) as HTMLTextAreaElement;
        expect(notesInput.value).toBe('Per QC Ordinance 2099-001.');
    });

    it('leaves contribution_code editable on a clone (admin can rename it)', () => {
        const cloneSource = {
            id: 7,
            contribution_code: 'CITY_TAX',
            label: 'QC tax',
            algorithm: 'flat_percentage',
            application_order: 50,
            applies_to: 'gross_pay' as const,
            rules: {},
            notes: null,
        };

        renderPage({ cloneSource });

        const codeInput = getCodeInput();
        // Override the cloned code.
        fireEvent.change(codeInput, { target: { value: 'MAKATI_TAX' } });
        expect(codeInput).toHaveValue('MAKATI_TAX');
    });
});
