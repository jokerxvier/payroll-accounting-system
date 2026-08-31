<?php

declare(strict_types=1);

use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\PaymentGatewaySetting;
use App\Services\Payments\Data\CheckoutUrls;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PayMongoGateway;
use App\Services\Payments\StripeGateway;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\Http;

/*
 * The two gateway drivers.
 *
 * Signature verification is the load-bearing test in this file. A webhook
 * whose signature is not checked is an open endpoint that marks invoices
 * paid — anyone who learns the URL can settle a document for free. Every
 * other property here matters less than that one.
 *
 * These are also the first Http::fake() tests in the suite; there was no
 * outbound HTTP anywhere in the app before this.
 */

beforeEach(function (): void {
    $this->setting = PaymentGatewaySetting::factory()->make([
        'secret_key' => 'sk_test_abcdef123456',
        'webhook_secret' => 'whsec_topsecret',
        'mode' => PaymentGatewaySetting::MODE_TEST,
    ]);

    $this->urls = new CheckoutUrls('https://school.test/ok', 'https://school.test/cancel');
});

function paymongo(): PayMongoGateway
{
    return app(PayMongoGateway::class);
}

function stripe(): StripeGateway
{
    return app(StripeGateway::class);
}

function payableInvoice(): Invoice
{
    $contact = Contact::factory()->customer()->create();

    return Invoice::factory()->create([
        'contact_id' => $contact->getKey(),
        'number' => 'INV-2026-00001',
        'total_centavos' => 112_000,
    ]);
}

/* ── Signatures: the security boundary ──────────────────────────────── */

it('accepts a correctly signed PayMongo payload', function (): void {
    $payload = '{"data":{"id":"evt_1"}}';
    $timestamp = '1799999999';
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_topsecret');

    expect(paymongo()->verifySignature(
        $this->setting,
        $payload,
        sprintf('t=%s,te=%s,li=deadbeef', $timestamp, $signature),
    ))->toBeTrue();
});

it('rejects a PayMongo payload whose body was altered after signing', function (): void {
    $timestamp = '1799999999';
    $signature = hash_hmac('sha256', $timestamp.'.'.'{"amount":100}', 'whsec_topsecret');

    // The attacker keeps the signature and raises the amount.
    expect(paymongo()->verifySignature(
        $this->setting,
        '{"amount":100000}',
        sprintf('t=%s,te=%s', $timestamp, $signature),
    ))->toBeFalse();
});

it('will not let a test-mode signature validate a live payload', function (): void {
    $payload = '{"data":{"id":"evt_1"}}';
    $timestamp = '1799999999';
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_topsecret');

    $live = PaymentGatewaySetting::factory()->make([
        'webhook_secret' => 'whsec_topsecret',
        'mode' => PaymentGatewaySetting::MODE_LIVE,
    ]);

    // Signed into the `te` slot, but this school is live, so `li` is read.
    expect(paymongo()->verifySignature(
        $live,
        $payload,
        sprintf('t=%s,te=%s', $timestamp, $signature),
    ))->toBeFalse();
});

it('rejects a PayMongo payload with no signature header at all', function (): void {
    expect(paymongo()->verifySignature($this->setting, '{}', ''))->toBeFalse();
});

it('accepts a correctly signed Stripe payload', function (): void {
    $payload = '{"id":"evt_1"}';
    $timestamp = '1799999999';
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_topsecret');

    expect(stripe()->verifySignature(
        $this->setting,
        $payload,
        sprintf('t=%s,v1=%s', $timestamp, $signature),
    ))->toBeTrue();
});

it('accepts a Stripe payload signed with any of several rotating secrets', function (): void {
    $payload = '{"id":"evt_1"}';
    $timestamp = '1799999999';
    $good = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_topsecret');

    // Stripe sends more than one v1 while a signing secret is rotating.
    expect(stripe()->verifySignature(
        $this->setting,
        $payload,
        sprintf('t=%s,v1=%s,v1=%s', $timestamp, str_repeat('0', 64), $good),
    ))->toBeTrue();
});

it('rejects a Stripe payload signed with the wrong secret', function (): void {
    $payload = '{"id":"evt_1"}';
    $timestamp = '1799999999';
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_theirs');

    expect(stripe()->verifySignature(
        $this->setting,
        $payload,
        sprintf('t=%s,v1=%s', $timestamp, $signature),
    ))->toBeFalse();
});

/* ── Parsing: one shape out of two very different payloads ──────────── */

it('normalises a paid PayMongo event, fee included', function (): void {
    $event = paymongo()->parseEvent([
        'data' => [
            'id' => 'evt_paymongo_1',
            'attributes' => [
                'type' => 'payment.paid',
                'data' => [
                    'id' => 'pay_abc',
                    'attributes' => [
                        'amount' => 112_000,
                        'fee' => 2_800,
                        'metadata' => ['invoice_id' => '42'],
                    ],
                ],
            ],
        ],
    ]);

    expect($event->eventId)->toBe('evt_paymongo_1')
        ->and($event->invoiceId)->toBe(42)
        ->and($event->grossCentavos)->toBe(112_000)
        ->and($event->feeCentavos)->toBe(2_800)
        ->and($event->netCentavos())->toBe(109_200)
        ->and($event->paymentReference)->toBe('pay_abc')
        ->and($event->isPaid)->toBeTrue();
});

it('normalises a paid Stripe event to the identical shape', function (): void {
    $event = stripe()->parseEvent([
        'id' => 'evt_stripe_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_1',
            'amount_total' => 112_000,
            'payment_intent' => 'pi_abc',
            'metadata' => ['invoice_id' => '42'],
            'balance_transaction' => ['fee' => 2_800],
        ]],
    ]);

    expect($event->eventId)->toBe('evt_stripe_1')
        ->and($event->invoiceId)->toBe(42)
        ->and($event->grossCentavos)->toBe(112_000)
        ->and($event->feeCentavos)->toBe(2_800)
        ->and($event->paymentReference)->toBe('pi_abc')
        ->and($event->isPaid)->toBeTrue();
});

it('marks a non-payment event as unpaid rather than guessing', function (): void {
    // A refund posted as a receipt would be worse than one not posted.
    $refund = stripe()->parseEvent([
        'id' => 'evt_2',
        'type' => 'charge.refunded',
        'data' => ['object' => ['amount_total' => 112_000, 'metadata' => []]],
    ]);

    expect($refund->isPaid)->toBeFalse();
});

it('reports a zero fee when the gateway did not disclose one', function (): void {
    // Stripe only exposes the fee on an expanded balance transaction. Zero is
    // the honest answer, and posts cash gross so the gap is visible on a
    // reconciliation rather than being invented.
    $event = stripe()->parseEvent([
        'id' => 'evt_3',
        'type' => 'checkout.session.completed',
        'data' => ['object' => ['amount_total' => 112_000, 'metadata' => ['invoice_id' => '7']]],
    ]);

    expect($event->feeCentavos)->toBe(0)
        ->and($event->netCentavos())->toBe(112_000);
});

/* ── Checkout creation ──────────────────────────────────────────────── */

it('sends the invoice id as metadata so the webhook can find it again', function (): void {
    Http::fake([
        'api.paymongo.com/*' => Http::response([
            'data' => ['id' => 'cs_1', 'attributes' => ['checkout_url' => 'https://pay.test/cs_1']],
        ]),
    ]);

    $invoice = payableInvoice();

    $session = paymongo()->createCheckout(
        $this->setting,
        $invoice,
        Money::fromCentavos(112_000),
        $this->urls,
    );

    expect($session->id)->toBe('cs_1')
        ->and($session->redirectUrl)->toBe('https://pay.test/cs_1');

    Http::assertSent(function ($request) use ($invoice): bool {
        $line = $request['data']['attributes'];

        return $line['line_items'][0]['amount'] === 112_000
            && $line['metadata']['invoice_id'] === (string) $invoice->getKey();
    });
});

it('surfaces a gateway refusal without leaking the secret key', function (): void {
    Http::fake([
        'api.paymongo.com/*' => Http::response(
            ['errors' => [['detail' => 'Bad key sk_test_abcdef123456']]],
            401,
        ),
    ]);

    $invoice = payableInvoice();

    expect(fn () => paymongo()->createCheckout(
        $this->setting,
        $invoice,
        Money::fromCentavos(112_000),
        $this->urls,
    ))
        ->toThrow(RuntimeException::class)
        // The key must not travel into logs or a flash banner.
        ->and(fn () => paymongo()->createCheckout(
            $this->setting,
            $invoice,
            Money::fromCentavos(112_000),
            $this->urls,
        ))->toThrow(function (RuntimeException $e): void {
            expect($e->getMessage())->not->toContain('abcdef123456')
                ->and($e->getMessage())->toContain('sk_test_***');
        });
});

/* ── The manager ────────────────────────────────────────────────────── */

it('refuses to resolve a driver it does not have', function (): void {
    app(PaymentGatewayManager::class)->driver('someothergateway');
})->throws(RuntimeException::class, 'No payment gateway driver');

it('treats a half-configured gateway as not set up', function (): void {
    PaymentGatewaySetting::factory()->create(['is_active' => true]);

    expect(app(PaymentGatewayManager::class)->settingsFor('paymongo'))->toBeNull()
        ->and(app(PaymentGatewayManager::class)->usable())->toBeEmpty();
});
