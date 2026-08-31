<?php

declare(strict_types=1);

namespace App\Services\Payments\Contracts;

use App\Models\Pas\Invoice;
use App\Models\Pas\PaymentGatewaySetting;
use App\Services\Payments\Data\CheckoutSession;
use App\Services\Payments\Data\CheckoutUrls;
use App\Services\Payments\Data\GatewayEvent;
use App\ValueObjects\Money;

/**
 * The seam between "a customer paid" and "which gateway they paid through".
 *
 * Implementations are the ONLY place a provider's wire format, authentication
 * scheme or signature algorithm may appear. Everything downstream — the
 * recording action, the posting service, the ledger — sees a
 * {@see GatewayEvent} and cannot tell PayMongo from Stripe.
 *
 * Each method takes the school's {@see PaymentGatewaySetting} explicitly
 * rather than resolving it internally. Credentials are per-school, and a
 * gateway client that reaches for ambient state is a gateway client that
 * eventually charges the wrong school's account.
 */
interface PaymentGateway
{
    /** The provider key this driver serves, e.g. `paymongo`. */
    public function provider(): string;

    /**
     * Open a checkout for one invoice and return where to send the payer.
     *
     * @throws \RuntimeException When the gateway refuses or is unreachable.
     */
    public function createCheckout(
        PaymentGatewaySetting $setting,
        Invoice $invoice,
        Money $amount,
        CheckoutUrls $urls,
    ): CheckoutSession;

    /**
     * Whether this raw body genuinely came from the gateway.
     *
     * Takes the RAW request body, not a decoded array — every provider signs
     * the exact bytes, and re-encoding a decoded payload changes them.
     */
    public function verifySignature(
        PaymentGatewaySetting $setting,
        string $payload,
        string $signatureHeader,
    ): bool;

    /**
     * Normalise a verified delivery, or null if it is one we do not act on.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseEvent(array $payload): ?GatewayEvent;
}
