import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AllowancesCreate from '@/pages/admin/allowances/create';

// Inertia's useForm internals do their own state management; the page
// renders the form synchronously, so we only need to stub the navigation
// primitives to mount the tree without a real Inertia provider.
vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        router: {
            post: vi.fn(),
            patch: vi.fn(),
        },
        usePage: () => ({ props: { auth: { user: null } } }),
        // Minimal useForm shim that supports the surface used by
        // <AllowanceForm>: data, errors, processing, isDirty, setData, post,
        // patch. Form submissions are no-ops for the test (they don't fire
        // a real request); we assert the field-level UX rules instead.
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setData] = useState<T>(initial);
            const setField = (
                key: keyof T | Partial<T>,
                value?: T[keyof T],
            ): void => {
                if (typeof key === 'string') {
                    setData((prev) => ({ ...prev, [key]: value }));
                } else {
                    setData((prev) => ({ ...prev, ...(key as Partial<T>) }));
                }
            };

            return {
                data,
                errors: {} as Record<string, string>,
                processing: false,
                isDirty: false,
                setData: setField,
                post: vi.fn(),
                patch: vi.fn(),
                reset: vi.fn(),
                clearErrors: vi.fn(),
                setDefaults: vi.fn(),
            };
        },
    };
});

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

function getCapInput(): HTMLInputElement {
    return screen.getByLabelText(/de-minimis cap/i) as HTMLInputElement;
}

function getDeMinimisSwitch(): HTMLElement {
    return screen.getByRole('switch', { name: /de-minimis/i });
}

describe('AllowancesCreate (de-minimis cap field behavior)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the de-minimis cap input as disabled when is_de_minimis is false', () => {
        render(<AllowancesCreate />);

        const cap = getCapInput();
        expect(cap).toBeDisabled();
        expect(cap).not.toBeRequired();
        expect(cap).toHaveValue('');
    });

    it('enables and requires the de-minimis cap input when the switch is flipped on', async () => {
        render(<AllowancesCreate />);

        const switchEl = getDeMinimisSwitch();
        fireEvent.click(switchEl);

        const cap = getCapInput();
        expect(cap).toBeEnabled();
        expect(cap).toBeRequired();
    });

    it('clears the de-minimis cap input when the switch flips back off', async () => {
        render(<AllowancesCreate />);

        // Turn on, set a value
        fireEvent.click(getDeMinimisSwitch());
        const cap = getCapInput();
        fireEvent.change(cap, { target: { value: '2000' } });
        expect(cap).toHaveValue('2000');

        // Turn off — value should clear and field disable
        fireEvent.click(getDeMinimisSwitch());
        const capAfter = getCapInput();
        expect(capAfter).toBeDisabled();
        expect(capAfter).toHaveValue('');
    });
});
