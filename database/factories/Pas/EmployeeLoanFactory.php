<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeLoan>
 */
class EmployeeLoanFactory extends Factory
{
    protected $model = EmployeeLoan::class;

    /**
     * Default: an open loan with PHP 30,000 principal, full balance still
     * outstanding, PHP 2,500 monthly amortization (12-month payoff),
     * started a month ago.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'code' => fake()->unique()->bothify('LN-####-???'),
            'principal_centavos' => 3_000_000,            // PHP 30,000
            'outstanding_balance_centavos' => 3_000_000,  // full balance
            'monthly_amortization_centavos' => 250_000,   // PHP 2,500
            'schedule' => 'every_run',
            'started_on' => now()->subMonth()->toDateString(),
            'closed_on' => null,
            'lock_version' => 0,
            'notes' => null,
        ];
    }

    /**
     * Mark the loan as closed (fully paid). Sets outstanding to zero and
     * stamps closed_on with the supplied date (defaults to today).
     */
    public function closed(?string $closedOn = null): self
    {
        return $this->state(fn () => [
            'outstanding_balance_centavos' => 0,
            'closed_on' => $closedOn ?? now()->toDateString(),
        ]);
    }

    /**
     * Set a specific outstanding balance to simulate a partly-paid loan.
     */
    public function outstanding(int $centavos): self
    {
        return $this->state(fn () => ['outstanding_balance_centavos' => $centavos]);
    }
}
