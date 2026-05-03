<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\Allowance;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAllowance>
 */
class EmployeeAllowanceFactory extends Factory
{
    protected $model = EmployeeAllowance::class;

    /**
     * Default: an open-ended subscription starting one year ago, every-run
     * schedule, PHP 2,000 amount. The parent FK columns are auto-resolved
     * via factories so callers can `EmployeeAllowance::factory()->create()`
     * without constructing the parents themselves.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'allowance_id' => Allowance::factory(),
            'amount_centavos' => 200_000, // PHP 2,000
            'schedule' => 'every_run',
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
            'notes' => null,
        ];
    }

    public function closed(string $effectiveTo): self
    {
        return $this->state(fn () => ['effective_to' => $effectiveTo]);
    }

    public function firstHalf(): self
    {
        return $this->state(fn () => ['schedule' => 'first_half']);
    }

    public function secondHalf(): self
    {
        return $this->state(fn () => ['schedule' => 'second_half']);
    }
}
