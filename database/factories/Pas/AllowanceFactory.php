<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\Allowance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Allowance>
 */
class AllowanceFactory extends Factory
{
    protected $model = Allowance::class;

    /**
     * Default: a generic taxable allowance with no de-minimis cap. Use
     * the named state methods (`riceSubsidy()`, `transportation()`, etc.)
     * for realistic Philippine catalog samples.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('ALW_???'),
            'name' => fake()->words(2, true),
            'is_taxable' => true,
            'is_de_minimis' => false,
            'de_minimis_cap_centavos' => null,
            'default_amount_centavos' => fake()->numberBetween(50_000, 500_000),
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function riceSubsidy(): self
    {
        return $this->state(fn () => [
            'code' => 'rice_subsidy',
            'name' => 'Rice Subsidy',
            'is_taxable' => false,
            'is_de_minimis' => true,
            'de_minimis_cap_centavos' => null, // BIR cap pending — see Q3 in PLAN.md
            'default_amount_centavos' => 200_000, // PHP 2,000
        ]);
    }

    public function transportation(): self
    {
        return $this->state(fn () => [
            'code' => 'transportation',
            'name' => 'Transportation Allowance',
            'is_taxable' => true,
            'is_de_minimis' => false,
            'default_amount_centavos' => 300_000, // PHP 3,000
        ]);
    }

    public function meal(): self
    {
        return $this->state(fn () => [
            'code' => 'meal',
            'name' => 'Meal Allowance',
            'is_taxable' => true,
            'is_de_minimis' => false,
            'default_amount_centavos' => 200_000, // PHP 2,000
        ]);
    }

    public function communication(): self
    {
        return $this->state(fn () => [
            'code' => 'communication',
            'name' => 'Communication Allowance',
            'is_taxable' => true,
            'is_de_minimis' => false,
            'default_amount_centavos' => 100_000, // PHP 1,000
        ]);
    }
}
