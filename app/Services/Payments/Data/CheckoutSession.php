<?php

declare(strict_types=1);

namespace App\Services\Payments\Data;

/**
 * A checkout the gateway has created and is waiting for the payer to complete.
 *
 * `id` is stored so the webhook that arrives later can be tied back to the
 * document that started it — a payer may open several checkouts for the same
 * invoice before one succeeds.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public string $redirectUrl,
    ) {}
}
