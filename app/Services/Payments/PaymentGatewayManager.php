<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Pas\PaymentGatewaySetting;
use App\Services\Payments\Contracts\PaymentGateway;
use RuntimeException;

/**
 * Resolves the driver and the credentials for the school that is current.
 *
 * The credentials are the reason this exists. Drivers are stateless and could
 * be resolved from the container directly; the settings row cannot, because
 * it is per-school and reading the wrong one would charge the wrong school's
 * merchant account. Keeping both lookups in one place means a caller cannot
 * pair School A's driver with School B's keys.
 *
 * Everything here reads through `PaymentGatewaySetting`'s tenant scope, so a
 * caller with no resolved tenant gets nothing rather than another school's
 * row — but note that `BelongsToTenant` **fails open** when no tenant is
 * current, so callers on public or queued paths must have resolved one.
 */
final class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $drivers;

    public function __construct(
        PayMongoGateway $payMongo,
        StripeGateway $stripe,
    ) {
        $this->drivers = [
            $payMongo->provider() => $payMongo,
            $stripe->provider() => $stripe,
        ];
    }

    /**
     * @throws RuntimeException When the provider is unknown.
     */
    public function driver(string $provider): PaymentGateway
    {
        return $this->drivers[$provider]
            ?? throw new RuntimeException(sprintf('No payment gateway driver for [%s].', $provider));
    }

    /**
     * The active, fully configured settings row for a provider, or null.
     *
     * Null rather than an exception: "this school has not set Stripe up" is a
     * normal state that the UI has to render, not an error.
     */
    public function settingsFor(string $provider): ?PaymentGatewaySetting
    {
        $setting = PaymentGatewaySetting::query()
            ->forProvider($provider)
            ->active()
            ->first();

        return $setting?->isUsable() === true ? $setting : null;
    }

    /**
     * Every provider this school can actually take money through, right now.
     *
     * @return list<PaymentGatewaySetting>
     */
    public function usable(): array
    {
        return array_values(array_filter(
            PaymentGatewaySetting::query()->active()->get()->all(),
            fn (PaymentGatewaySetting $s): bool => $s->isUsable(),
        ));
    }

    /**
     * @throws RuntimeException When the school cannot take money through it.
     */
    public function requireSettingsFor(string $provider): PaymentGatewaySetting
    {
        return $this->settingsFor($provider)
            ?? throw new RuntimeException(sprintf(
                'This school has no active, fully configured %s settings.',
                $provider,
            ));
    }
}
