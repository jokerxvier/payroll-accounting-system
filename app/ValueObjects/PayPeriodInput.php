<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Services\Statutory\StatutoryContributionResolver;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Stringable;

/**
 * Immutable description of a single payroll cutoff window.
 *
 * A pay period is the calendar slice of time that a `PayrollComputationResult`
 * is computed against. Two frequencies are supported in v1:
 *
 *   - `monthly`       — covers a full calendar month (1st through last day).
 *                        Days-in-period varies between 28 and 31.
 *
 *   - `semi_monthly`  — covers either the first half (day 1 through day 15)
 *                        or the second half (day 16 through last day) of a
 *                        calendar month. Days-in-period varies between 13
 *                        (short February) and 16 (31-day month).
 *
 * The `frequency` constants intentionally match the BIR `period_type` rule
 * vocabulary so the engine can pass the value straight through to
 * {@see StatutoryContributionResolver} without
 * translation.
 *
 * Construction is forced through static factories. Each factory normalises
 * input dates to start-of-day so stray time components can never sneak into
 * a comparison. Every operation returns a NEW instance — instances are
 * referentially transparent and safe to share across requests.
 */
final readonly class PayPeriodInput implements Stringable
{
    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_SEMI_MONTHLY = 'semi_monthly';

    /** @var list<string> */
    private const ALLOWED_FREQUENCIES = [
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_SEMI_MONTHLY,
    ];

    private function __construct(
        private CarbonImmutable $start,
        private CarbonImmutable $end,
        private string $frequency,
    ) {}

    /**
     * Build a full-calendar-month period.
     *
     * @throws InvalidArgumentException When `$month` is outside [1,12].
     */
    public static function monthly(int $year, int $month): self
    {
        self::guardMonth($month);

        $start = CarbonImmutable::create($year, $month, 1)?->startOfDay();
        if ($start === null) {
            throw new InvalidArgumentException(
                "PayPeriodInput::monthly cannot construct a valid date for {$year}-{$month}."
            );
        }

        $end = $start->endOfMonth()->startOfDay();

        return new self($start, $end, self::FREQUENCY_MONTHLY);
    }

    /**
     * Build the first half of a calendar month (day 1 through day 15 inclusive).
     *
     * @throws InvalidArgumentException When `$month` is outside [1,12].
     */
    public static function semiMonthlyFirst(int $year, int $month): self
    {
        self::guardMonth($month);

        $start = CarbonImmutable::create($year, $month, 1)?->startOfDay();
        $end = CarbonImmutable::create($year, $month, 15)?->startOfDay();
        if ($start === null || $end === null) {
            throw new InvalidArgumentException(
                "PayPeriodInput::semiMonthlyFirst cannot construct a valid date for {$year}-{$month}."
            );
        }

        return new self($start, $end, self::FREQUENCY_SEMI_MONTHLY);
    }

    /**
     * Build the second half of a calendar month (day 16 through last day inclusive).
     * The `end` date varies based on the month's length (28/29/30/31).
     *
     * @throws InvalidArgumentException When `$month` is outside [1,12].
     */
    public static function semiMonthlySecond(int $year, int $month): self
    {
        self::guardMonth($month);

        $start = CarbonImmutable::create($year, $month, 16)?->startOfDay();
        if ($start === null) {
            throw new InvalidArgumentException(
                "PayPeriodInput::semiMonthlySecond cannot construct a valid date for {$year}-{$month}."
            );
        }

        $end = $start->endOfMonth()->startOfDay();

        return new self($start, $end, self::FREQUENCY_SEMI_MONTHLY);
    }

    /**
     * Build a custom period. Used by tests and reserved for future flexibility
     * (e.g., an off-cycle correction window). Validates:
     *
     *   - `$end >= $start`
     *   - `$frequency` is one of the FREQUENCY_* constants.
     *   - `daysInPeriod()` falls in the expected range for the frequency:
     *       monthly       → 28..31 days
     *       semi_monthly  → 13..16 days
     *
     * @throws InvalidArgumentException When any of the above guards fail.
     */
    public static function custom(CarbonImmutable $start, CarbonImmutable $end, string $frequency): self
    {
        if (! in_array($frequency, self::ALLOWED_FREQUENCIES, true)) {
            throw new InvalidArgumentException(
                "PayPeriodInput::custom received unknown frequency '{$frequency}'; expected one of: "
                .implode(', ', self::ALLOWED_FREQUENCIES).'.'
            );
        }

        $start = $start->startOfDay();
        $end = $end->startOfDay();

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException(
                'PayPeriodInput::custom requires $end to be on or after $start; got '
                ."{$start->toDateString()}..{$end->toDateString()}."
            );
        }

        // (end - start) + 1, since both ends are inclusive.
        // Carbon's diffInDays() returns float; the difference between two
        // start-of-day CarbonImmutable values is always whole, so casting is
        // safe (and required for the int return type below).
        $days = (int) $start->diffInDays($end) + 1;

        if ($frequency === self::FREQUENCY_MONTHLY && ($days < 28 || $days > 31)) {
            throw new InvalidArgumentException(
                "PayPeriodInput::custom monthly period must span 28..31 days; got {$days}."
            );
        }

        if ($frequency === self::FREQUENCY_SEMI_MONTHLY && ($days < 13 || $days > 16)) {
            throw new InvalidArgumentException(
                "PayPeriodInput::custom semi_monthly period must span 13..16 days; got {$days}."
            );
        }

        return new self($start, $end, $frequency);
    }

    public function start(): CarbonImmutable
    {
        return $this->start;
    }

    public function end(): CarbonImmutable
    {
        return $this->end;
    }

    public function frequency(): string
    {
        return $this->frequency;
    }

    /**
     * Number of inclusive calendar days the period spans.
     *
     * Both `$start` and `$end` count, so a period from 2026-05-01 to 2026-05-31
     * inclusive yields 31, not 30.
     */
    public function daysInPeriod(): int
    {
        // See `custom()` — diffInDays is float in current Carbon, but the
        // difference between two start-of-day CarbonImmutable values is
        // always whole, so the cast is safe.
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    public function isMonthly(): bool
    {
        return $this->frequency === self::FREQUENCY_MONTHLY;
    }

    public function isSemiMonthly(): bool
    {
        return $this->frequency === self::FREQUENCY_SEMI_MONTHLY;
    }

    /**
     * Equality across all three carrying fields. Time-of-day is irrelevant
     * because every factory normalises to start-of-day.
     */
    public function equals(self $other): bool
    {
        return $this->frequency === $other->frequency
            && $this->start->equalTo($other->start)
            && $this->end->equalTo($other->end);
    }

    /**
     * Debug-friendly cast: `"YYYY-MM-DD..YYYY-MM-DD (frequency)"`.
     * Round-trippable through `custom()` for the same inputs.
     */
    public function __toString(): string
    {
        return $this->start->toDateString().'..'.$this->end->toDateString().' ('.$this->frequency.')';
    }

    private static function guardMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException(
                "PayPeriodInput month must be in [1,12]; got {$month}."
            );
        }
    }
}
