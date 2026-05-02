import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { InlineMoneyEdit } from '@/components/inline-money-edit';

function getEditButton(label = 'Basic salary'): HTMLElement {
    return screen.getByRole('button', { name: `Edit ${label}` });
}

function getEditInput(label = 'Basic salary'): HTMLInputElement {
    return screen.getByRole('textbox', { name: label }) as HTMLInputElement;
}

function getConfirmButton(): HTMLElement {
    return screen.getByRole('button', { name: 'Confirm edit' });
}

function getCancelButton(): HTMLElement {
    return screen.getByRole('button', { name: 'Cancel edit' });
}

function setInputValue(input: HTMLInputElement, value: string) {
    fireEvent.change(input, { target: { value } });
}

describe('InlineMoneyEdit', () => {
    it('renders Money in display mode with the formatted value', () => {
        render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={vi.fn()}
                label="Basic salary"
            />,
        );

        expect(getEditButton()).toHaveTextContent('₱1,234.56');
    });

    it('switches to an input pre-filled with the peso value when clicked', () => {
        render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={vi.fn()}
                label="Basic salary"
            />,
        );

        fireEvent.click(getEditButton());

        const input = getEditInput();
        expect(input).toHaveValue('1234.56');
        expect(input).toHaveFocus();
    });

    it('calls onSave with centavos when the confirm button is clicked', async () => {
        const onSave = vi.fn().mockResolvedValue(undefined);

        render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={onSave}
                label="Basic salary"
            />,
        );

        fireEvent.click(getEditButton());
        setInputValue(getEditInput(), '2000.50');

        await act(async () => {
            fireEvent.click(getConfirmButton());
        });

        expect(onSave).toHaveBeenCalledTimes(1);
        expect(onSave).toHaveBeenCalledWith(200050);
    });

    it('calls onSave when Enter is pressed', async () => {
        const onSave = vi.fn().mockResolvedValue(undefined);

        render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={onSave}
                label="Basic salary"
            />,
        );

        fireEvent.click(getEditButton());
        const input = getEditInput();
        setInputValue(input, '5000');

        await act(async () => {
            fireEvent.keyDown(input, { key: 'Enter' });
        });

        expect(onSave).toHaveBeenCalledTimes(1);
        expect(onSave).toHaveBeenCalledWith(500000);
    });

    it('reverts and exits edit mode when the cancel button is clicked', () => {
        const onSave = vi.fn();

        render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={onSave}
                label="Basic salary"
            />,
        );

        fireEvent.click(getEditButton());
        setInputValue(getEditInput(), '9999.99');

        fireEvent.click(getCancelButton());

        expect(onSave).not.toHaveBeenCalled();
        expect(getEditButton()).toHaveTextContent('₱1,234.56');
    });

    it('reverts and exits edit mode when Escape is pressed', () => {
        const onSave = vi.fn();

        render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={onSave}
                label="Basic salary"
            />,
        );

        fireEvent.click(getEditButton());
        const input = getEditInput();
        setInputValue(input, '9999.99');

        fireEvent.keyDown(input, { key: 'Escape' });

        expect(onSave).not.toHaveBeenCalled();
        expect(getEditButton()).toHaveTextContent('₱1,234.56');
    });

    it('rejects negative input without calling onSave and stays in edit mode', async () => {
        const onSave = vi.fn();

        render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={onSave}
                label="Basic salary"
            />,
        );

        fireEvent.click(getEditButton());
        setInputValue(getEditInput(), '-100');

        await act(async () => {
            fireEvent.click(getConfirmButton());
        });

        expect(onSave).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent(/valid amount/i);
        expect(getEditInput()).toBeInTheDocument();
    });

    it('rejects non-numeric input without calling onSave', async () => {
        const onSave = vi.fn();

        render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={onSave}
                label="Basic salary"
            />,
        );

        fireEvent.click(getEditButton());
        setInputValue(getEditInput(), 'abc');

        await act(async () => {
            fireEvent.click(getConfirmButton());
        });

        expect(onSave).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent(/valid amount/i);
    });

    it('does not call onSave when confirming without a change', async () => {
        const onSave = vi.fn();

        render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={onSave}
                label="Basic salary"
            />,
        );

        fireEvent.click(getEditButton());

        await act(async () => {
            fireEvent.click(getConfirmButton());
        });

        expect(onSave).not.toHaveBeenCalled();
        expect(getEditButton()).toHaveTextContent('₱1,234.56');
    });

    it('renders plain Money with no edit button when disabled', () => {
        render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={vi.fn()}
                label="Basic salary"
                disabled
            />,
        );

        expect(
            screen.queryByRole('button', { name: /edit basic salary/i }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('₱1,234.56')).toBeInTheDocument();
    });

    it('shows the draft value while a save is in flight', async () => {
        const onSave = vi.fn(
            () =>
                new Promise<void>(() => {
                    /* never resolves */
                }),
        );

        const { rerender } = render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={onSave}
                label="Basic salary"
            />,
        );

        fireEvent.click(getEditButton());
        setInputValue(getEditInput(), '7777.77');

        await act(async () => {
            fireEvent.click(getConfirmButton());
        });

        expect(onSave).toHaveBeenCalledWith(777777);
        rerender(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={onSave}
                label="Basic salary"
            />,
        );
        expect(getEditButton()).toHaveTextContent('₱7,777.77');
    });

    it('reverts to the prop value when onSave rejects', async () => {
        const onSave = vi
            .fn()
            .mockRejectedValue(new Error('basic_salary_centavos: too large'));

        render(
            <InlineMoneyEdit
                valueCentavos={123456}
                onSave={onSave}
                label="Basic salary"
            />,
        );

        fireEvent.click(getEditButton());
        setInputValue(getEditInput(), '8888');

        await act(async () => {
            fireEvent.click(getConfirmButton());
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(onSave).toHaveBeenCalledWith(888800);
        expect(getEditButton()).toHaveTextContent('₱1,234.56');
    });
});
