<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\EmployeeProfile;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Compute the basic pay for an employee over a single pay period.
 *
 * The output of this action is the "basic earning" line of a payroll run,
 * before any allowances, overtime, or statutory deductions are applied.
 *
 * Algorithm:
 *
 *   1. Inactive employees earn nothing (`is_active = false` → zero).
 *   2. Employees whose tenure does not overlap the period earn nothing
 *      (hired after period end, or terminated before period start).
 *   3. The full-period basic is the monthly salary for a `monthly` period,
 *      and the monthly salary divided by two for a `semi_monthly` period.
 *      Division uses {@see Money::dividedBy()} which applies banker's
 *      rounding to handle odd-centavo monthly salaries cleanly.
 *   4. Pro-ration is daily: `(daysWorked / daysInPeriod) * fullPeriodBasic`.
 *      The worked-day window is the intersection of `[date_hired, date_terminated]`
 *      with the pay period; both endpoints are inclusive, so a single
 *      day counts as one. When `daysWorked >= daysInPeriod` we return the
 *      full-period basic unchanged (no pro-ration noise from rounding).
 *
 * The action is stateless and side-effect-free; it can safely be invoked
 * concurrently for many employees in a batch.
 */
final class ComputeBasicPay
{
    /**
     * @param  EmployeeProfile  $profile  Profile carrying `basic_salary_centavos`,
     *                                    `is_active`, `date_hired`, `date_terminated`.
     *                                    The model casts the two date columns to
     *                                    `CarbonImmutable` (or null) via the global
     *                                    `Date::use(CarbonImmutable::class)` binding.
     */
    public function __invoke(EmployeeProfile $profile, PayPeriodInput $period): Money
    {
        if (! $profile->is_active) {
            return Money::zero();
        }

        $dateHired = $profile->date_hired;
        $dateTerminated = $profile->date_terminated;

        // Hired strictly after the period ends → no overlap, nothing earned.
        if ($dateHired !== null && $dateHired->greaterThan($period->end())) {
            return Money::zero();
        }

        // Terminated strictly before the period starts → no overlap.
        if ($dateTerminated !== null && $dateTerminated->lessThan($period->start())) {
            return Money::zero();
        }

        $monthly = Money::fromCentavos($profile->basic_salary_centavos);

        $fullPeriodBasic = $period->isSemiMonthly()
            ? $monthly->dividedBy(2)
            : $monthly;

        // Worked-day window = intersection of [date_hired, date_terminated]
        // with [period.start, period.end]. Endpoints are inclusive on both
        // sides, so the day-count is `(end - start) + 1`.
        $workStart = $dateHired !== null && $dateHired->greaterThan($period->start())
            ? $dateHired
            : $period->start();

        $workEnd = $dateTerminated !== null && $dateTerminated->lessThan($period->end())
            ? $dateTerminated
            : $period->end();

        // Carbon's diffInDays() returns a float; the difference between two
        // start-of-day CarbonImmutable values is always whole, so casting to
        // int is safe (mirrors PayPeriodInput::daysInPeriod()).
        $daysWorked = (int) $workStart->diffInDays($workEnd) + 1;

        if ($daysWorked >= $period->daysInPeriod()) {
            return $fullPeriodBasic;
        }

        return $fullPeriodBasic
            ->times($daysWorked)
            ->dividedBy($period->daysInPeriod());
    }
}
