import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CounterpartyPicker } from '@/components/admin/counterparty-picker';
import type { ContactPickerOption } from '@/types';

/*
 * The picker three forms share.
 *
 * It was a private function inside invoice-form.tsx until the payment and
 * recurring-schedule forms needed the same behaviour. These are the
 * assertions that belong to the widget rather than to any document — the
 * forms' own tests cover the wiring.
 */

const CONTACTS: ContactPickerOption[] = [
    { id: 1, name: 'Dela Cruz Family', tin: null },
    { id: 2, name: 'Ana Reyes', tin: '123-456-789-000' },
    { id: 3, name: 'Barangay Malanday', tin: null },
];

function renderPicker(
    props: Partial<React.ComponentProps<typeof CounterpartyPicker>> = {},
) {
    const onSelect = vi.fn();

    render(
        <CounterpartyPicker
            id="contact_id"
            noun="customer"
            options={CONTACTS}
            value={null}
            disabled={false}
            onSelect={onSelect}
            {...props}
        />,
    );

    return { onSelect };
}

/** The trigger is labelled by the caller's <Label htmlFor>, so find by role. */
function trigger(): HTMLElement {
    return screen.getByRole('combobox');
}

describe('choosing a counterparty', () => {
    it('says what it is for before anything is chosen', () => {
        renderPicker();

        expect(trigger()).toHaveTextContent('Choose a customer');
    });

    it('takes its noun from the calling document', () => {
        // A bill says supplier. One prop rather than three, because the
        // placeholder, the empty state and the New button all need it.
        renderPicker({ noun: 'supplier' });

        expect(trigger()).toHaveTextContent('Choose a supplier');
    });

    it('hands back the id that was picked', () => {
        const { onSelect } = renderPicker();

        fireEvent.click(trigger());
        fireEvent.click(screen.getByRole('option', { name: /Ana Reyes/ }));

        expect(onSelect).toHaveBeenCalledWith(2);
    });

    it('shows the chosen name and its TIN on the trigger', () => {
        renderPicker({ value: 2 });

        expect(trigger()).toHaveTextContent('Ana Reyes');
        expect(trigger()).toHaveTextContent('123-456-789-000');
    });

    it('filters by name', () => {
        renderPicker();

        fireEvent.click(trigger());
        fireEvent.change(
            screen.getByPlaceholderText(/search by name or tin/i),
            {
                target: { value: 'reyes' },
            },
        );

        expect(
            screen.getByRole('option', { name: /Ana Reyes/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: /Barangay Malanday/ }),
        ).not.toBeInTheDocument();
    });

    it('filters by TIN, which is what a BIR document shows', () => {
        // An operator holding a document has the TIN, not the spelling.
        renderPicker();

        fireEvent.click(trigger());
        fireEvent.change(
            screen.getByPlaceholderText(/search by name or tin/i),
            {
                target: { value: '123-456-789' },
            },
        );

        expect(
            screen.getByRole('option', { name: /Ana Reyes/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: /Dela Cruz/ }),
        ).not.toBeInTheDocument();
    });

    it('says so when nothing matches', () => {
        renderPicker();

        fireEvent.click(trigger());
        fireEvent.change(
            screen.getByPlaceholderText(/search by name or tin/i),
            {
                target: { value: 'nobody at all' },
            },
        );

        expect(screen.getByText('No customer found.')).toBeInTheDocument();
    });
});

describe('an empty register', () => {
    it('names the gap rather than opening onto nothing', () => {
        // An empty picker and a picker with nothing matching the filter look
        // identical, and only one of them has an obvious next action.
        renderPicker({ options: [], disabled: true });

        expect(trigger()).toHaveTextContent('No customers yet');
        expect(trigger()).toBeDisabled();
    });

    it('stays openable when the operator can create one', () => {
        const onAddNew = vi.fn();
        renderPicker({ options: [], disabled: false, onAddNew });

        expect(trigger()).not.toBeDisabled();

        fireEvent.click(trigger());
        fireEvent.click(screen.getByRole('button', { name: /new customer/i }));

        expect(onAddNew).toHaveBeenCalledTimes(1);
    });
});

describe('creating one from the picker', () => {
    it('offers it only when the caller passes a handler', () => {
        renderPicker();

        fireEvent.click(trigger());

        expect(
            screen.queryByRole('button', { name: /new customer/i }),
        ).not.toBeInTheDocument();
    });

    it('keeps the offer visible when the search finds nothing', () => {
        // The action lives outside CommandList on purpose: an item inside it
        // is filtered like any other, and the moment the search comes up empty
        // is exactly when "create this one" is what the operator wants.
        renderPicker({ onAddNew: vi.fn() });

        fireEvent.click(trigger());
        fireEvent.change(
            screen.getByPlaceholderText(/search by name or tin/i),
            {
                target: { value: 'nobody at all' },
            },
        );

        expect(
            screen.getByRole('button', { name: /new customer/i }),
        ).toBeInTheDocument();
    });
});
