<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollAdjustment>
 */
class PayrollAdjustmentFactory extends Factory
{
    protected $model = PayrollAdjustment::class;

    /**
     * Default: a taxable PHP 1,000 addition applied today, no period link
     * cached. The Week-9 batch backfill populates `applied_pay_period_id`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'kind' => 'addition',
            'is_taxable' => true,
            'amount_centavos' => 100_000, // PHP 1,000
            'applies_on' => now()->toDateString(),
            'label' => fake()->sentence(3),
            'reason' => null,
            'applied_pay_period_id' => null,
        ];
    }

    public function deduction(): self
    {
        return $this->state(fn () => ['kind' => 'deduction']);
    }

    public function nonTaxable(): self
    {
        return $this->state(fn () => ['is_taxable' => false]);
    }

    public function appliesOn(string $date): self
    {
        return $this->state(fn () => ['applies_on' => $date]);
    }
}
