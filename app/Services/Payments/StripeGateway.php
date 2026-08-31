<?php

declare(strict_types=1);

namespace App\Services\Payments;

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
 * Stripe — for card payers, including those outside the Philippines.
 *
 * Same shape as {@see PayMongoGateway} and deliberately not more clever: the
 * two drivers exist so their differences stay local. Three of those
 * differences are worth naming, because they are why one interface with two
 * implementations beats one class with branches:
 *
 *   1. **Auth** is a bearer token, not HTTP Basic.
 *   2. **Encoding** is form-encoded with bracketed nesting, not JSON.
 *   3. **The fee is not in the webhook.** Stripe reports it on the balance
 *      transaction behind the charge, so obtaining it costs a second call.
 *      PayMongo puts it on the payment resource. The `GatewayEvent` DTO hides
 *      that entirely from the posting path.
 */
final class StripeGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.stripe.com/v1';

    private const TIMEOUT_SECONDS = 15;

    public function provider(): string
    {
        return PaymentGatewaySetting::PROVIDER_STRIPE;
    }

    public function createCheckout(
        PaymentGatewaySetting $setting,
        Invoice $invoice,
        Money $amount,
        CheckoutUrls $urls,
    ): CheckoutSession {
        $response = Http::withToken((string) $setting->secret_key)
            ->asForm()
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->retry(2, 200, throw: false)
            ->post(self::BASE_URL.'/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $urls->success,
                'cancel_url' => $urls->cancel,
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]' => 'php',
                'line_items[0][price_data][product_data][name]' => $this->lineName($invoice),
                // Stripe also takes minor units, so `centavos()` maps 1:1.
                'line_items[0][price_data][unit_amount]' => $amount->centavos(),
                // Round-tripped back to us on the webhook. Without it a
                // delivery cannot be tied to a document.
                'metadata[invoice_id]' => (string) $invoice->getKey(),
                'metadata[school_id]' => (string) $invoice->school_id,
                // Carried onto the PaymentIntent too, because a
                // `payment_intent.succeeded` delivery does not carry the
                // session's metadata.
                'payment_intent_data[metadata][invoice_id]' => (string) $invoice->getKey(),
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Stripe refused the checkout (HTTP %d): %s',
                $response->status(),
                $this->scrub($response->body()),
            ));
        }

        $id = $response->json('id');
        $url = $response->json('url');

        if (! is_string($id) || ! is_string($url)) {
            throw new RuntimeException('Stripe returned a checkout with no id or URL.');
        }

        return new CheckoutSession($id, $url);
    }

    /**
     * Stripe signs `{timestamp}.{raw body}` with HMAC-SHA256.
     *
     * The `Stripe-Signature` header is `t=<ts>,v1=<hex>[,v1=<hex>…]` — more
     * than one `v1` appears while a signing secret is being rotated, and any
     * of them matching is a valid signature.
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

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $chunk) {
            $pair = explode('=', trim($chunk), 2);
            if (count($pair) !== 2) {
                continue;
            }

            if ($pair[0] === 't') {
                $timestamp = $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if (! is_string($timestamp) || $signatures === []) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function parseEvent(array $payload): ?GatewayEvent
    {
        $eventId = data_get($payload, 'id');
        $type = data_get($payload, 'type');

        if (! is_string($eventId) || ! is_string($type)) {
            return null;
        }

        $isPaid = in_array($type, ['checkout.session.completed', 'payment_intent.succeeded'], true);

        $object = data_get($payload, 'data.object', []);
        $invoiceId = data_get($object, 'metadata.invoice_id');

        // `amount_total` on a session, `amount_received` on a payment intent.
        $gross = data_get($object, 'amount_total')
            ?? data_get($object, 'amount_received')
            ?? 0;

        // Present only when the caller expanded the balance transaction. Zero
        // is the honest answer otherwise — better a fee of zero, which posts
        // cash gross and is visibly wrong on a reconciliation, than a guess.
        $fee = data_get($object, 'balance_transaction.fee')
            ?? data_get($payload, 'data.object.charges.data.0.balance_transaction.fee')
            ?? 0;

        return new GatewayEvent(
            eventId: $eventId,
            type: $type,
            invoiceId: is_numeric($invoiceId) ? (int) $invoiceId : null,
            grossCentavos: (int) $gross,
            feeCentavos: (int) $fee,
            paymentReference: is_string(data_get($object, 'payment_intent'))
                ? (string) data_get($object, 'payment_intent')
                : (is_string(data_get($object, 'id')) ? (string) data_get($object, 'id') : null),
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

    private function scrub(string $message): string
    {
        return (string) preg_replace('/(sk_(?:test|live)_)[A-Za-z0-9]+/', '$1***', $message);
    }
}
