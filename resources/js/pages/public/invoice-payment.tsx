import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, Clock, Lock } from 'lucide-react';
import type { FormEvent } from 'react';
import { Money } from '@/components/money';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';

/**
 * The only page in this application a customer sees.
 *
 * No sidebar, no navigation, no account — the person here has one thing to
 * do. Everything on the page either states what is owed or takes the payment;
 * anything else would be an invitation to wander somewhere they have no
 * access to.
 *
 * It reports the ledger's view, never the gateway's. Coming back from a
 * successful checkout does not mean the money has been confirmed — the
 * webhook decides that — so a return with no receipt yet says so plainly
 * instead of showing a receipt we cannot stand behind.
 */

interface InvoiceLine {
    description: string | null;
    quantity: string;
    amount_centavos: number;
}

interface Props {
    invoice: {
        number: string | null;
        issue_date: string;
        due_date: string | null;
        contact_name: string | null;
        total_centavos: number;
        amount_paid_centavos: number;
        balance_due_centavos: number;
        terms: string | null;
        lines: InvoiceLine[];
    };
    school: {
        name: string | null;
        tin: string | null;
        address: string | null;
    };
    methods: string[];
    paid: boolean;
    justReturned?: boolean;
}

const PROVIDER_LABELS: Record<string, string> = {
    paymongo: 'GCash, Maya, GrabPay or card',
    stripe: 'Card',
};

export default function InvoicePayment({
    invoice,
    school,
    methods,
    paid,
    justReturned = false,
}: Props) {
    const form = useForm({ provider: methods[0] ?? 'paymongo' });

    const pay = (provider: string) => (e: FormEvent) => {
        e.preventDefault();
        // transform() returns void in Inertia v3, so it is a statement here
        // rather than something to chain the post off.
        form.transform(() => ({ provider }));
        form.post(`${window.location.pathname}/checkout`);
    };

    return (
        <div className="min-h-screen bg-muted/30 px-4 py-10">
            <Head title={`Pay ${invoice.number ?? 'invoice'}`} />

            <div className="mx-auto max-w-2xl space-y-6">
                <header className="space-y-1 text-center">
                    <h1 className="text-2xl font-semibold">{school.name}</h1>
                    {school.tin && (
                        <p className="text-sm text-muted-foreground">
                            TIN {school.tin}
                        </p>
                    )}
                </header>

                {paid ? (
                    <Card className="border-success/40 bg-success/5">
                        <CardContent className="flex items-start gap-3 pt-6">
                            <CheckCircle2 className="mt-0.5 size-5 text-success" />
                            <div>
                                <p className="font-medium">
                                    This invoice is settled
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Nothing further is due. Keep this page for
                                    your reference.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ) : justReturned ? (
                    <Card className="border-amber-500/40 bg-amber-500/5">
                        <CardContent className="flex items-start gap-3 pt-6">
                            <Clock className="mt-0.5 size-5 text-amber-600" />
                            <div>
                                <p className="font-medium">
                                    Waiting for confirmation
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Your bank or wallet has not confirmed the
                                    payment to us yet. This usually takes a few
                                    moments — refresh shortly. Do not pay again.
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-baseline justify-between gap-4">
                            <span>Invoice {invoice.number}</span>
                            <span className="font-mono text-sm font-normal text-muted-foreground">
                                {invoice.issue_date}
                            </span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {invoice.contact_name && (
                            <p className="text-sm text-muted-foreground">
                                Billed to {invoice.contact_name}
                            </p>
                        )}

                        <div className="space-y-2">
                            {invoice.lines.map((line, i) => (
                                <div
                                    key={i}
                                    className="flex items-baseline justify-between gap-4 text-sm"
                                >
                                    <span>
                                        {line.description ?? 'Charge'}
                                        {Number(line.quantity) !== 1 && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                × {line.quantity}
                                            </span>
                                        )}
                                    </span>
                                    <span className="tabular-nums">
                                        <Money
                                            amount={line.amount_centavos / 100}
                                        />
                                    </span>
                                </div>
                            ))}
                        </div>

                        <Separator />

                        <dl className="space-y-1 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">Total</dt>
                                <dd className="tabular-nums">
                                    <Money
                                        amount={invoice.total_centavos / 100}
                                    />
                                </dd>
                            </div>
                            {invoice.amount_paid_centavos > 0 && (
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        Already paid
                                    </dt>
                                    <dd className="tabular-nums">
                                        <Money
                                            amount={
                                                invoice.amount_paid_centavos /
                                                100
                                            }
                                        />
                                    </dd>
                                </div>
                            )}
                            <div className="flex justify-between text-base font-medium">
                                <dt>Amount due</dt>
                                <dd className="tabular-nums">
                                    <Money
                                        amount={
                                            invoice.balance_due_centavos / 100
                                        }
                                    />
                                </dd>
                            </div>
                        </dl>

                        {invoice.terms && (
                            <p className="text-sm text-muted-foreground">
                                {invoice.terms}
                            </p>
                        )}
                    </CardContent>
                </Card>

                {!paid && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Pay now</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {methods.length === 0 ? (
                                // Honest rather than a dead button: the school
                                // has not finished setting a gateway up.
                                <p className="text-sm text-muted-foreground">
                                    Online payment is not available for this
                                    invoice yet. Please contact the school to
                                    arrange payment.
                                </p>
                            ) : (
                                methods.map((provider) => (
                                    <form
                                        key={provider}
                                        onSubmit={pay(provider)}
                                    >
                                        <Button
                                            type="submit"
                                            className="w-full"
                                            disabled={form.processing}
                                        >
                                            Pay{' '}
                                            <Money
                                                amount={
                                                    invoice.balance_due_centavos /
                                                    100
                                                }
                                            />{' '}
                                            with{' '}
                                            {PROVIDER_LABELS[provider] ??
                                                provider}
                                        </Button>
                                    </form>
                                ))
                            )}

                            <p className="flex items-center justify-center gap-1.5 text-xs text-muted-foreground">
                                <Lock className="size-3" />
                                Payment is handled by the provider. This school
                                never sees your card details.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {school.address && (
                    <p className="text-center text-xs text-muted-foreground">
                        {school.address}
                    </p>
                )}
            </div>
        </div>
    );
}
