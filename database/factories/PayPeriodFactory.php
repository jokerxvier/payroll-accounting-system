<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pas\PayPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayPeriod>
 */
class PayPeriodFactory extends Factory
{
    protected $model = PayPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::create(2026, 5, 1);
        $end = $start?->endOfMonth()->startOfDay();

        return [
            'code' => '2026-'.fake()->unique()->numberBetween(1, 9999),
            'frequency' => PayPeriod::FREQUENCY_MONTHLY,
            'start_date' => $start,
            'end_date' => $end,
            'cutoff_date' => null,
            'status' => PayPeriod::STATUS_DRAFT,
        ];
    }

    public function monthly(int $year, int $month): self
    {
        return $this->state(function () use ($year, $month): array {
            $start = CarbonImmutable::create($year, $month, 1);
            $end = $start?->endOfMonth()->startOfDay();

            return [
                'code' => sprintf('%04d-%02d', $year, $month),
                'frequency' => PayPeriod::FREQUENCY_MONTHLY,
                'start_date' => $start,
                'end_date' => $end,
            ];
        });
    }

    public function open(): self
    {
        return $this->state(fn (): array => ['status' => PayPeriod::STATUS_OPEN]);
    }

    public function closed(): self
    {
        return $this->state(fn (): array => ['status' => PayPeriod::STATUS_CLOSED]);
    }
}
