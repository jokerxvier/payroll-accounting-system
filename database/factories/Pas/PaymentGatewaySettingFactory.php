<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\PaymentGatewaySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentGatewaySetting>
 */
final class PaymentGatewaySettingFactory extends Factory
{
    protected $model = PaymentGatewaySetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => PaymentGatewaySetting::PROVIDER_PAYMONGO,
            'mode' => PaymentGatewaySetting::MODE_TEST,
            'publishable_key' => 'pk_test_'.fake()->regexify('[A-Za-z0-9]{24}'),
            'secret_key' => 'sk_test_'.fake()->regexify('[A-Za-z0-9]{24}'),
            'webhook_secret' => 'whsec_'.fake()->regexify('[A-Za-z0-9]{32}'),
            'cash_account_id' => null,
            'fee_account_id' => null,
            'is_active' => false,
        ];
    }

    public function stripe(): self
    {
        return $this->state(fn (): array => [
            'provider' => PaymentGatewaySetting::PROVIDER_STRIPE,
        ]);
    }

    public function live(): self
    {
        return $this->state(fn (): array => [
            'mode' => PaymentGatewaySetting::MODE_LIVE,
            'publishable_key' => 'pk_live_'.fake()->regexify('[A-Za-z0-9]{24}'),
            'secret_key' => 'sk_live_'.fake()->regexify('[A-Za-z0-9]{24}'),
        ]);
    }

    /**
     * Active AND complete — the only state `isUsable()` accepts.
     *
     * Takes the accounts explicitly rather than creating them, because a
     * gateway's cash account has to be one of the school's own
     * cash-equivalent accounts, not a fresh row nobody has seen.
     */
    public function usable(ChartOfAccount $cash, ChartOfAccount $fee): self
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'cash_account_id' => $cash->getKey(),
            'fee_account_id' => $fee->getKey(),
        ]);
    }
}
