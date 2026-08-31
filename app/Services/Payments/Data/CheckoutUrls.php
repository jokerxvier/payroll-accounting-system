<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

/**
 * Where a gateway sends the payer back to.
 *
 * Both URLs point at our own return route, which never trusts them for
 * anything: a customer can reach `success` by editing the address bar. The
 * invoice is marked paid by the webhook and nothing else.
 */
final readonly class CheckoutUrls
{
    public function __construct(
        public string $success,
        public string $cancel,
    ) {}
}
