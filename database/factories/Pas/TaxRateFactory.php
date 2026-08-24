<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    /**
     * Default: the standard 12% Philippine output VAT.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('TAX_???'),
            'name' => fake()->words(2, true),
            'rate_bps' => 1200,
            'type' => TaxRate::TYPE_VAT_SALES,
            'account_id' => null,
            'is_active' => true,
        ];
    }

    /** Standard 12% output VAT on sales. */
    public function vatSales(): self
    {
        return $this->state(fn (): array => [
            'code' => 'VAT_12_SALES',
            'name' => 'VAT 12% (Sales)',
            'rate_bps' => 1200,
            'type' => TaxRate::TYPE_VAT_SALES,
        ]);
    }

    /** Standard 12% input VAT on purchases. */
    public function vatPurchase(): self
    {
        return $this->state(fn (): array => [
            'code' => 'VAT_12_PURCHASE',
            'name' => 'VAT 12% (Purchases)',
            'rate_bps' => 1200,
            'type' => TaxRate::TYPE_VAT_PURCHASE,
        ]);
    }

    /** VAT-exempt — zero tax, but its own invoice subtotal. */
    public function exempt(): self
    {
        return $this->state(fn (): array => [
            'code' => 'VAT_EXEMPT',
            'name' => 'VAT Exempt',
            'rate_bps' => 0,
            'type' => TaxRate::TYPE_EXEMPT,
            'account_id' => null,
        ]);
    }

    /** Zero-rated — zero tax, reported separately from exempt. */
    public function zeroRated(): self
    {
        return $this->state(fn (): array => [
            'code' => 'VAT_ZERO',
            'name' => 'Zero-Rated',
            'rate_bps' => 0,
            'type' => TaxRate::TYPE_ZERO_RATED,
            'account_id' => null,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
