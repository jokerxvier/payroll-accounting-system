<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

use App\ValueObjects\Money;

/**
 * One webhook delivery, normalised.
 *
 * This is the type that lets the posting path stay ignorant of which gateway
 * paid. PayMongo and Stripe disagree about almost everything on the wire —
 * envelope shape, where the fee lives, what a successful payment is called —
 * and every one of those differences is absorbed by the driver that produces
 * this object.
 *
 * Amounts are integer centavos, matching {@see Money} and
 * both gateways' minor units, so no conversion arithmetic happens anywhere.
 */
final readonly class GatewayEvent
{
    public function __construct(
        /** The gateway's id for this delivery. The idempotency key. */
        public string $eventId,
        public string $type,
        /** Our invoice id, round-tripped through the checkout's metadata. */
        public ?int $invoiceId,
        /** What the customer paid, before the gateway took its cut. */
        public int $grossCentavos,
        /** What the gateway kept. Zero when it did not report one. */
        public int $feeCentavos,
        /** The gateway's id for the payment itself, for reconciliation. */
        public ?string $paymentReference,
        /** Whether this delivery represents money actually received. */
        public bool $isPaid,
    ) {}

    /** What actually reaches the bank. */
    public function netCentavos(): int
    {
        return $this->grossCentavos - $this->feeCentavos;
    }
}
