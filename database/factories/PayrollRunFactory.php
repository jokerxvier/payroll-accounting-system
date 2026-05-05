<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pay_period_id' => PayPeriod::factory(),
            'status' => PayrollRun::STATUS_DRAFT,
            'total_employees' => 0,
            'total_employee_deductions_centavos' => 0,
            'total_employer_contributions_centavos' => 0,
            'total_net_pay_centavos' => 0,
            'started_at' => null,
            'computed_at' => null,
            'approved_at' => null,
            'approved_by_user_id' => null,
            'voided_at' => null,
            'voided_by_user_id' => null,
        ];
    }

    public function computed(): self
    {
        return $this->state(fn (): array => [
            'status' => PayrollRun::STATUS_COMPUTED,
            'started_at' => now()->subMinutes(5),
            'computed_at' => now()->subMinute(),
        ]);
    }

    public function approved(): self
    {
        return $this->state(fn (): array => [
            'status' => PayrollRun::STATUS_APPROVED,
            'started_at' => now()->subHours(2),
            'computed_at' => now()->subHour(),
            'approved_at' => now()->subMinutes(30),
        ]);
    }

    public function voided(): self
    {
        return $this->state(fn (): array => [
            'status' => PayrollRun::STATUS_VOIDED,
            'voided_at' => now()->subMinutes(10),
        ]);
    }
}
