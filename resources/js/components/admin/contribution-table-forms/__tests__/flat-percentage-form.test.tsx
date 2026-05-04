import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { FlatPercentageForm } from '@/components/admin/contribution-table-forms/flat-percentage-form';

/**
 * Mounts <FlatPercentageForm> with a real React state owner so the form's
 * value/onChange contract round-trips just like it does inside the parent
 * <create> page. Returns the latest emitted payload via a getter so assertions
 * read the most recent value.
 *
 * Uses fireEvent (not user-event) to match the convention of the other
 * Vitest specs in this repo and to avoid pulling in a new test dependency.
 */
function mountForm(initial: Record<string, unknown> = {}) {
    let latest: Record<string, unknown> = { ...initial };
    const onChangeSpy = vi.fn((next: Record<string, unknown>) => {
        latest = next;
    });

    function Harness() {
        const [value, setValue] = useState<Record<string, unknown>>(initial);

        return (
            <FlatPercentageForm
                value={value}
                onChange={(next) => {
                    setValue(next);
                    onChangeSpy(next);
                }}
            />
        );
    }

    const utils = render(<Harness />);

    return {
        ...utils,
        onChangeSpy,
        getLatest: () => latest,
    };
}

function changeValue(input: HTMLInputElement, value: string): void {
    fireEvent.change(input, { target: { value } });
}

describe('FlatPercentageForm', () => {
    it('renders all rule fields with their default values', () => {
        mountForm();

        const rate = screen.getByLabelText(/^Rate$/i) as HTMLInputElement;
        const ee = screen.getByLabelText(
            /^Employee share$/i,
        ) as HTMLInputElement;
        const er = screen.getByLabelText(
            /^Employer share$/i,
        ) as HTMLInputElement;
        const cap = screen.getByLabelText(/^Cap amount$/i) as HTMLInputElement;

        expect(rate).toBeInTheDocument();
        expect(rate).toHaveValue('');
        expect(ee).toHaveValue('100');
        expect(er).toHaveValue('0');
        expect(cap).toHaveValue('');
    });

    it('auto-derives the employer share when the employee share changes', () => {
        const { getLatest } = mountForm();

        const ee = screen.getByLabelText(
            /^Employee share$/i,
        ) as HTMLInputElement;

        changeValue(ee, '60');

        const er = screen.getByLabelText(
            /^Employer share$/i,
        ) as HTMLInputElement;

        expect(er).toHaveValue('40');
        expect(getLatest()).toMatchObject({
            employee_share_percent: '60',
            employer_share_percent: '40',
        });
    });

    it('emits the user-typed decimals (the backend converts to basis points)', () => {
        const { getLatest } = mountForm();

        const rate = screen.getByLabelText(/^Rate$/i) as HTMLInputElement;
        changeValue(rate, '10');

        // Employee share defaults to 100 → no further interaction needed.
        const payload = getLatest();
        expect(payload.rate_percent).toBe('10');
        expect(payload.employee_share_percent).toBe('100');
        expect(payload.employer_share_percent).toBe('0');
    });

    it('treats a blank cap as null on the wire (not 0)', () => {
        const { getLatest } = mountForm();

        const cap = screen.getByLabelText(/^Cap amount$/i) as HTMLInputElement;

        changeValue(cap, '50000');
        expect(getLatest().cap_amount).toBe('50000');

        changeValue(cap, '');
        expect(getLatest().cap_amount).toBeNull();
    });

    it('shows an inline error when the employee + employer shares do not total 100', () => {
        mountForm();

        const ee = screen.getByLabelText(
            /^Employee share$/i,
        ) as HTMLInputElement;
        const er = screen.getByLabelText(
            /^Employer share$/i,
        ) as HTMLInputElement;

        // First change auto-fills employer to 30, total 100 — still ok.
        changeValue(ee, '70');

        // Then manually override employer to 40 → total drifts to 110%.
        changeValue(er, '40');

        const alerts = screen.getAllByRole('alert');
        const shareAlert = alerts.find((node) =>
            (node.textContent ?? '').includes(
                'Employee + Employer must total 100%',
            ),
        );

        expect(shareAlert).toBeDefined();
        expect(shareAlert?.textContent).toMatch(/110\.00%/);
        expect(ee).toHaveAttribute('aria-invalid', 'true');
        expect(er).toHaveAttribute('aria-invalid', 'true');
    });

    it('marks the rate field invalid when the value is out of (0, 100]', () => {
        mountForm();

        const rate = screen.getByLabelText(/^Rate$/i) as HTMLInputElement;
        changeValue(rate, '150');

        expect(rate).toHaveAttribute('aria-invalid', 'true');
    });

    it('marks the cap field invalid when the value is negative', () => {
        mountForm();

        const cap = screen.getByLabelText(/^Cap amount$/i) as HTMLInputElement;
        changeValue(cap, '-10');

        expect(cap).toHaveAttribute('aria-invalid', 'true');
    });
});
