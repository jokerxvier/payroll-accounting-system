<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeDeduction>
 */
class EmployeeDeductionFactory extends Factory
{
    protected $model = EmployeeDeduction::class;

    /**
     * Default: an open-ended fixed-amount subscription. Pair with the
     * `percent()` state when the parent deduction type uses
     * calc_method='percent'.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'deduction_type_id' => DeductionType::factory(),
            'amount_centavos' => 50_000, // PHP 500
            'percent_basis_points' => null,
            'schedule' => 'every_run',
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
            'notes' => null,
        ];
    }

    /**
     * Switch to a percent-based deduction. The parent DeductionType row
     * should also use calc_method='percent' for application-level
     * consistency (the DB does not enforce this — Chunk-2 model
     * validation will).
     */
    public function percent(int $basisPoints = 250): self
    {
        return $this->state(fn () => [
            'amount_centavos' => null,
            'percent_basis_points' => $basisPoints,
            'deduction_type_id' => DeductionType::factory()->percent(),
        ]);
    }

    public function closed(string $effectiveTo): self
    {
        return $this->state(fn () => ['effective_to' => $effectiveTo]);
    }
}
