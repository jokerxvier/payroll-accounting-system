import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PaymentGatewaysIndex from '@/pages/admin/accounting/payment-gateways';

/*
 * The gateway settings screen.
 *
 * What is pinned here is an absence: the two account questions must NOT be on
 * the form. They have the same answer for almost every school, and asking them
 * of whoever is pasting API keys is what this change removed.
 */

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
    }),
}));

const DEFAULTS = {
    cash: { id: 1, code: '1110', name: 'Cash in Bank' },
    fee: { id: 2, code: '5250', name: 'Bank and Merchant Fees' },
};

function renderPage(overrides: Record<string, unknown> = {}) {
    return render(
        <PaymentGatewaysIndex
            settings={[
                {
                    provider: 'paymongo',
                    mode: 'test',
                    publishable_key: null,
                    secret_masked: null,
                    has_secret: false,
                    has_webhook_secret: false,
                    cash_account_id: null,
                    fee_account_id: null,
                    is_active: false,
                    is_usable: false,
                },
            ]}
            cashAccountOptions={[{ id: 1, code: '1110', name: 'Cash in Bank' }]}
            expenseAccountOptions={[
                { id: 2, code: '5250', name: 'Bank and Merchant Fees' },
            ]}
            webhookBaseUrl="https://school.test/schools/default/webhooks"
            defaults={DEFAULTS}
            {...overrides}
        />,
    );
}

describe('payment gateway settings', () => {
    it('states where money goes instead of asking', () => {
        renderPage();

        expect(screen.getByText(/1110 · Cash in Bank/)).toBeInTheDocument();
        expect(
            screen.getByText(/5250 · Bank and Merchant Fees/),
        ).toBeInTheDocument();
    });

    it('keeps both account pickers out of the way until asked for', () => {
        renderPage();

        // Collapsed by default — the fields exist, but nobody pasting an API
        // key has to look at them.
        expect(
            screen.queryByLabelText('Settled money lands in'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText('Gateway fee is expensed to'),
        ).not.toBeInTheDocument();

        expect(
            screen.getByRole('button', { name: /advanced/i }),
        ).toBeInTheDocument();
    });

    it('still shows the credential fields, which are the real question', () => {
        renderPage();

        expect(screen.getByLabelText('Secret key')).toBeInTheDocument();
        expect(
            screen.getByLabelText('Webhook signing secret'),
        ).toBeInTheDocument();
    });

    it('says so plainly when a default is missing', () => {
        renderPage({ defaults: { cash: null, fee: DEFAULTS.fee } });

        // Rendering a blank would leave someone guessing why the gateway
        // refuses to switch on.
        expect(
            screen.getByText(/a cash account that is missing/),
        ).toBeInTheDocument();
    });
});
