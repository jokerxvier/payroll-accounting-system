<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Http\Controllers\Admin\SchoolController;
use App\Models\Pas\Invoice;
use App\Models\Pas\PaymentGatewaySetting;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\CheckoutSession;
use App\Services\Payments\Data\CheckoutUrls;
use App\Services\Payments\Data\GatewayEvent;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayMongo — the Philippine methods parents actually use (GCash, Maya,
 * GrabPay, cards, online banking).
 *
 * Uses Laravel's HTTP client directly rather than an SDK. The surface needed
 * here is one endpoint and one signature check, and this is the first
 * outbound HTTP in the whole application — a dependency that large for that
 * little would be the wrong first precedent to set.
 *
 * Authentication is HTTP Basic with the secret key as the username and an
 * empty password, which `withBasicAuth` encodes for us.
 */
final class PayMongoGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.paymongo.com/v1';

    private const TIMEOUT_SECONDS = 15;

    public function provider(): string
    {
        return PaymentGatewaySetting::PROVIDER_PAYMONGO;
    }

    public function createCheckout(
        PaymentGatewaySetting $setting,
        Invoice $invoice,
        Money $amount,
        CheckoutUrls $urls,
    ): CheckoutSession {
        $response = Http::withBasicAuth((string) $setting->secret_key, '')
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            // Retry only the transport, never the semantics: a 4xx means the
            // request was wrong and repeating it will not help.
            ->retry(2, 200, throw: false)
            ->post(self::BASE_URL.'/checkout_sessions', [
                'data' => [
                    'attributes' => [
                        'line_items' => [[
                            'name' => $this->lineName($invoice),
                            'quantity' => 1,
                            'currency' => 'PHP',
                            // PayMongo takes centavos, which is what we
                            // already store — no conversion.
                            'amount' => $amount->centavos(),
                        ]],
                        'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay'],
                        'success_url' => $urls->success,
                        'cancel_url' => $urls->cancel,
                        'description' => $this->lineName($invoice),
                        // The only way the webhook knows which document was
                        // paid. Everything else in the payload is PayMongo's.
                        'metadata' => [
                            'invoice_id' => (string) $invoice->getKey(),
                            'school_id' => (string) $invoice->school_id,
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'PayMongo refused the checkout (HTTP %d): %s',
                $response->status(),
                $this->scrub($response->body()),
            ));
        }

        $id = $response->json('data.id');
        $url = $response->json('data.attributes.checkout_url');

        if (! is_string($id) || ! is_string($url)) {
            throw new RuntimeException('PayMongo returned a checkout with no id or URL.');
        }

        return new CheckoutSession($id, $url);
    }

    /**
     * PayMongo signs `{timestamp}.{raw body}` with HMAC-SHA256.
     *
     * The header looks like `t=1699999999,te=<hex>,li=<hex>` — `te` for test
     * mode, `li` for live. Whichever this school is configured for is the one
     * that must match; accepting either would let a test-mode signature
     * validate a live payload.
     */
    public function verifySignature(
        PaymentGatewaySetting $setting,
        string $payload,
        string $signatureHeader,
    ): bool {
        $secret = (string) $setting->webhook_secret;

        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $chunk) {
            $pair = explode('=', trim($chunk), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        $timestamp = $parts['t'] ?? null;
        $key = $setting->mode === PaymentGatewaySetting::MODE_LIVE ? 'li' : 'te';
        $provided = $parts[$key] ?? null;

        if (! is_string($timestamp) || ! is_string($provided)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return hash_equals($expected, $provided);
    }

    public function parseEvent(array $payload): ?GatewayEvent
    {
        $eventId = data_get($payload, 'data.id');
        $type = data_get($payload, 'data.attributes.type');

        if (! is_string($eventId) || ! is_string($type)) {
            return null;
        }

        // Everything else — refunds, failures, chargebacks — is recorded and
        // ignored rather than guessed at. A refund posted as a receipt would
        // be worse than a refund not posted at all.
        $isPaid = in_array($type, ['payment.paid', 'checkout_session.payment.paid'], true);

        $resource = data_get($payload, 'data.attributes.data.attributes', []);
        $metadata = data_get($resource, 'metadata', [])
            ?: data_get($payload, 'data.attributes.data.attributes.metadata', []);

        $invoiceId = data_get($metadata, 'invoice_id');

        return new GatewayEvent(
            eventId: $eventId,
            type: $type,
            invoiceId: is_numeric($invoiceId) ? (int) $invoiceId : null,
            grossCentavos: (int) (data_get($resource, 'amount') ?? 0),
            // PayMongo reports the fee on the payment resource itself, so
            // unlike Stripe no second call is needed.
            feeCentavos: (int) (data_get($resource, 'fee') ?? 0),
            paymentReference: is_string(data_get($payload, 'data.attributes.data.id'))
                ? (string) data_get($payload, 'data.attributes.data.id')
                : null,
            isPaid: $isPaid,
        );
    }

    private function lineName(Invoice $invoice): string
    {
        return sprintf(
            '%s %s',
            $invoice->isSales() ? 'Invoice' : 'Bill',
            $invoice->number ?? ('#'.$invoice->getKey()),
        );
    }

    /**
     * Never let a credential reach an exception message.
     *
     * The same habit as {@see SchoolController}
     * scrubbing the database password out of driver errors — exception
     * messages reach logs, flash banners and bug reports.
     */
    private function scrub(string $message): string
    {
        return (string) preg_replace('/(sk_(?:test|live)_)[A-Za-z0-9]+/', '$1***', $message);
    }
}
