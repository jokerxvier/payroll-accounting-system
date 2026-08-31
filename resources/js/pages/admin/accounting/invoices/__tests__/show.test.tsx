import { act, fireEvent, render, screen } from '@testing-library/react';
import { toast } from 'sonner';
import { describe, expect, it, vi } from 'vitest';
import InvoiceShow from '@/pages/admin/accounting/invoices/show';
import type { InvoiceDetail } from '@/types';

const routerPost = vi.fn();
const writeText = vi.fn((text: string) => Promise.resolve(text));

// jsdom ships no clipboard, and the real browser has none either over plain
// http — which is the whole reason the hook carries a fallback.
Object.defineProperty(navigator, 'clipboard', {
    value: { writeText: (text: string) => writeText(text) },
    configurable: true,
});

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

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function invoice(overrides: Partial<InvoiceDetail> = {}): InvoiceDetail {
    return {
        id: 7,
        type: 'sales',
        number: 'INV-2026-00007',
        is_recurring: false,
        reference: null,
        contact_name: 'Dela Cruz Family',
        issue_date: '2026-08-01',
        due_date: '2026-08-16',
        status: 'approved',
        total_centavos: 500_000,
        amount_paid_centavos: 0,
        balance_due_centavos: 500_000,
        is_vat_inclusive: false,
        vatable_sales_centavos: 500_000,
        vat_exempt_sales_centavos: 0,
        zero_rated_sales_centavos: 0,
        vat_centavos: 0,
        notes: null,
        terms: null,
        approved_at: '2026-08-01T02:00:00+00:00',
        sent_at: null,
        sent_to: null,
        pay_url: null,
        voided_at: null,
        void_reason: null,
        contact: {
            id: 3,
            name: 'Dela Cruz Family',
            tin: null,
            email: 'family@example.test',
            address: null,
        },
        journal_entry: null,
        lines: [],
        payments: [],
        can: {
            update: false,
            delete: false,
            approve: false,
            void: true,
            print: true,
            send: true,
            ...(overrides.can ?? {}),
        },
        ...overrides,
    };
}

function renderShow(overrides: Partial<InvoiceDetail> = {}): void {
    routerPost.mockClear();
    writeText.mockClear();
    vi.mocked(toast.error).mockClear();
    vi.mocked(toast.success).mockClear();
    render(<InvoiceShow invoice={invoice(overrides)} />);
}

function openSendDialog(): void {
    fireEvent.click(screen.getByRole('button', { name: /send by email/i }));
}

describe('sending an invoice by email', () => {
    it('offers the action only when the server says it is allowed', () => {
        renderShow({
            can: {
                update: false,
                delete: false,
                approve: false,
                void: false,
                print: true,
                send: false,
            },
        });

        expect(
            screen.queryByRole('button', { name: /send by email/i }),
        ).not.toBeInTheDocument();
    });

    /*
     * The dialog is the whole point of the feature: an invoice must not leave
     * the building on a single click, because the address is the thing most
     * likely to be wrong.
     */
    it('asks before sending anything', () => {
        renderShow();

        openSendDialog();

        expect(
            screen.getByText(/Send INV-2026-00007 by email\?/),
        ).toBeInTheDocument();
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('fills the address from the customer on file', () => {
        renderShow();

        openSendDialog();

        expect(
            (screen.getByLabelText('Send to') as HTMLInputElement).value,
        ).toBe('family@example.test');
    });

    it('posts the address to the send route', () => {
        renderShow();

        openSendDialog();
        fireEvent.click(screen.getByRole('button', { name: 'Send invoice' }));

        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost.mock.calls[0][0]).toBe('/admin/invoices/7/send');
        expect(routerPost.mock.calls[0][1]).toEqual({
            email: 'family@example.test',
        });
    });

    it('sends the edited address, not the one it was seeded with', () => {
        renderShow();

        openSendDialog();
        fireEvent.change(screen.getByLabelText('Send to'), {
            target: { value: 'lola@example.test' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send invoice' }));

        expect(routerPost.mock.calls[0][1]).toEqual({
            email: 'lola@example.test',
        });
    });

    it('will not send to an empty box', () => {
        renderShow({ contact: null });

        openSendDialog();

        expect(
            screen.getByRole('button', { name: 'Send invoice' }),
        ).toBeDisabled();
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('names the gap when the customer has no address on file', () => {
        renderShow({
            contact: {
                id: 3,
                name: 'Dela Cruz Family',
                tin: null,
                email: null,
                address: null,
            },
        });

        openSendDialog();

        expect(
            screen.getByText(/has no email address on file/i),
        ).toBeInTheDocument();
    });
});

/*
 * A refusal has to land where the operator is looking. The server sends
 * anything typing can fix as a validation error on `email`, which both keeps
 * the dialog open and gives the message a place under the box it is about.
 */
/*
 * The copy button did nothing at all, for two independent reasons, and the
 * first hid the second: the page read `flash.payLink`, which nothing ever set,
 * and returned early — so it never reached `navigator.clipboard`, which does
 * not exist over plain http anyway.
 */
describe('copying the pay link', () => {
    /** The link the server hands back on the re-rendered invoice. */
    const LINK = 'http://payroll-system.test/schools/demo/pay/tok123';

    function clickCopy(): void {
        fireEvent.click(screen.getByRole('button', { name: /copy pay link/i }));
    }

    /** Fire the onSuccess Inertia would call, with the re-rendered page. */
    function respondWith(payUrl: string | null): void {
        const options = routerPost.mock.calls[0][2] as {
            onSuccess: (page: unknown) => void;
            onFinish: () => void;
        };

        act(() => {
            options.onSuccess({
                props: { invoice: invoice({ pay_url: payUrl }) },
            });
            options.onFinish();
        });
    }

    it('posts to the pay-link route', () => {
        renderShow();

        clickCopy();

        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost.mock.calls[0][0]).toBe('/admin/invoices/7/pay-link');
    });

    it('reads the link off the invoice, not off a flash', () => {
        renderShow();

        clickCopy();
        respondWith(LINK);

        expect(writeText).toHaveBeenCalledWith(LINK);
    });

    it('confirms the copy so the button is not silent', async () => {
        renderShow();

        clickCopy();
        respondWith(LINK);
        await act(async () => {});

        expect(toast.success).toHaveBeenCalledWith(
            'Pay link copied',
            expect.objectContaining({ description: LINK }),
        );
    });

    it('says so when the link could not be built', () => {
        renderShow();

        clickCopy();
        respondWith(null);

        expect(toast.error).toHaveBeenCalledWith(
            'The pay link could not be built.',
        );
        expect(writeText).not.toHaveBeenCalled();
    });

    it('shows the link on the page once it exists', () => {
        // The clipboard can refuse for reasons the operator cannot act on, so
        // the link has to be somewhere it can be selected by hand.
        renderShow({ pay_url: LINK });

        expect(screen.getByTestId('pay-link')).toHaveTextContent(LINK);
    });

    it('shows no pay link block before one is minted', () => {
        renderShow();

        expect(screen.queryByTestId('pay-link')).not.toBeInTheDocument();
    });
});

describe('when the send is refused', () => {
    /** Fire the onError Inertia would call, with the server's error bag. */
    function failWith(errors: Record<string, string>): void {
        const options = routerPost.mock.calls[0][2] as {
            onError: (errors: Record<string, string>) => void;
            onFinish: () => void;
        };

        act(() => {
            options.onError(errors);
            options.onFinish();
        });
    }

    function attemptSend(): void {
        renderShow();
        openSendDialog();
        fireEvent.click(screen.getByRole('button', { name: 'Send invoice' }));
    }

    it('shows the reason under the address, not somewhere else', () => {
        attemptSend();

        failWith({
            email: 'Dela Cruz Family has no email address on file. Type one to send this invoice.',
        });

        expect(
            screen.getByText(/has no email address on file/i),
        ).toBeInTheDocument();
    });

    it('keeps the dialog open so the address can be corrected', () => {
        attemptSend();

        failWith({ email: 'The email must be a valid email address.' });

        expect(screen.getByLabelText('Send to')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Send invoice' }),
        ).toBeInTheDocument();
    });

    it('marks the box itself as the problem', () => {
        attemptSend();

        failWith({ email: 'The email must be a valid email address.' });

        expect(screen.getByLabelText('Send to')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
    });

    it('drops the complaint once the address changes', () => {
        // It was about what was in the box. That stops being true the moment
        // the box does, and a stale error under a corrected address reads as
        // a second failure.
        attemptSend();

        failWith({ email: 'The email must be a valid email address.' });
        fireEvent.change(screen.getByLabelText('Send to'), {
            target: { value: 'fixed@example.test' },
        });

        expect(
            screen.queryByText(/must be a valid email address/i),
        ).not.toBeInTheDocument();
    });

    it('starts clean when the dialog is opened again', () => {
        attemptSend();
        failWith({ email: 'The email must be a valid email address.' });

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        openSendDialog();

        expect(
            screen.queryByText(/must be a valid email address/i),
        ).not.toBeInTheDocument();
    });

    it('falls back to a toast when the failure is not about the address', () => {
        attemptSend();

        failWith({ someOtherField: 'Something else went wrong.' });

        expect(toast.error).toHaveBeenCalledWith(
            'Could not send this invoice.',
        );
    });

    it('does not also toast when the message is already on screen', () => {
        // The same complaint in two places, one of which disappears on a
        // timer, is worse than one that stays put.
        attemptSend();

        failWith({ email: 'The email must be a valid email address.' });

        expect(toast.error).not.toHaveBeenCalled();
    });
});

describe('an invoice that has already been sent', () => {
    const sent = {
        status: 'sent' as const,
        sent_at: '2026-09-03T06:15:00+00:00',
        sent_to: 'lola@example.test',
    };

    it('offers a re-send rather than a first send', () => {
        renderShow(sent);

        expect(
            screen.getByRole('button', { name: /send again/i }),
        ).toBeInTheDocument();
    });

    /*
     * The address it last went to beats the one on file: a re-send usually
     * follows a correction, and retyping the correction every time invites
     * the original typo back.
     */
    it('defaults to the address it last went to', () => {
        renderShow(sent);

        fireEvent.click(screen.getByRole('button', { name: /send again/i }));

        expect(
            (screen.getByLabelText('Send to') as HTMLInputElement).value,
        ).toBe('lola@example.test');
    });

    it('shows where and when it went, which is what a dispute needs', () => {
        renderShow(sent);

        expect(
            // 'Sept', not 'Sep' — that is what en-GB's CLDR data gives for
            // September, and the regex tolerates both so the assertion does
            // not hinge on an ICU detail.
            screen.getByText(/Emailed to lola@example\.test on 3 Sept? 2026/),
        ).toBeInTheDocument();
    });
});
