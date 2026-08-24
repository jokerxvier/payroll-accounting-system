<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\AccountingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingPeriod>
 */
class AccountingPeriodFactory extends Factory
{
    protected $model = AccountingPeriod::class;

    /**
     * Default: an open calendar-month period in the current month.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now()->startOfMonth();

        return [
            'code' => $start->format('Y-m'),
            'name' => $start->format('F Y'),
            'start_date' => $start,
            'end_date' => $start->endOfMonth(),
            'fiscal_year' => (int) $start->format('Y'),
            'status' => AccountingPeriod::STATUS_OPEN,
            'closed_at' => null,
            'closed_by_user_id' => null,
            'reopened_at' => null,
            'reopened_by_user_id' => null,
        ];
    }

    /**
     * A period covering the calendar month containing `$month`.
     */
    public function forMonth(CarbonImmutable $month): self
    {
        $start = $month->startOfMonth();

        return $this->state(fn (): array => [
            'code' => $start->format('Y-m'),
            'name' => $start->format('F Y'),
            'start_date' => $start,
            'end_date' => $start->endOfMonth(),
            'fiscal_year' => (int) $start->format('Y'),
        ]);
    }

    public function closed(): self
    {
        return $this->state(fn (): array => [
            'status' => AccountingPeriod::STATUS_CLOSED,
            'closed_at' => CarbonImmutable::now(),
        ]);
    }
}
