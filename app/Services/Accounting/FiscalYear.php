<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\AccountingPeriod;
use Carbon\CarbonImmutable;

/**
 * Which dates a school's financial year covers.
 *
 * `pas_accounting_periods.fiscal_year` has existed since the periods table was
 * extended, added for "the year-end retained-earnings roll-forward, which
 * groups periods by fiscal year rather than by calendar date range" — and
 * nothing has read it since. Every report so far has taken an explicit from/to
 * from the operator, so no code has ever had to answer "which year are we in".
 * A dashboard has to, because it opens on a default range before anyone has
 * chosen anything.
 *
 * **A fiscal year is the periods, not the calendar.** A school whose year runs
 * June to March has a 2026 fiscal year spanning two calendar years, and asking
 * `CarbonImmutable::now()->startOfYear()` would report ten months of it and
 * call that the year. The bounds come from the periods carrying that
 * `fiscal_year`, which is the only place the school has said what its year is.
 *
 * Reads through `AccountingPeriod` rather than `AccountingPeriodGuard`: the
 * guard throws on a closed period, which is right for a posting and wrong for
 * a report. A dashboard must be able to look at a year that has been closed.
 */
final class FiscalYear
{
    /**
     * The fiscal year the given date falls in, or null when no period covers it.
     *
     * Null is an ordinary answer, not a failure: a school that has not set up
     * periods yet, or one looking at a date outside them, has no fiscal year
     * to report and the caller falls back to the calendar.
     */
    public function covering(CarbonImmutable $date): ?int
    {
        $period = AccountingPeriod::query()
            ->covering($date)
            ->first();

        return $period?->fiscal_year;
    }

    /**
     * The first and last day of a fiscal year.
     *
     * Derived from the periods themselves — `min(start_date)` to
     * `max(end_date)` — so a year with a stub first period or a thirteenth
     * adjustment period is bounded by what the school actually recorded
     * rather than by an assumed twelve months. Uses the
     * `pas_acct_periods_school_fy_idx` index.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function range(int $fiscalYear): ?array
    {
        // Through the query builder rather than Eloquent: this selects two
        // aggregates, not a period, and hydrating a model whose every other
        // attribute is absent invites someone to read one.
        $bounds = AccountingPeriod::query()
            ->where('fiscal_year', $fiscalYear)
            ->toBase()
            ->selectRaw('MIN(start_date) as starts_on, MAX(end_date) as ends_on')
            ->first();

        $startsOn = $bounds?->starts_on;
        $endsOn = $bounds?->ends_on;

        if (! is_string($startsOn) || ! is_string($endsOn)) {
            return null;
        }

        return [
            CarbonImmutable::parse($startsOn)->startOfDay(),
            CarbonImmutable::parse($endsOn)->startOfDay(),
        ];
    }

    /**
     * The range a report should open on, given today.
     *
     * The current fiscal year when the school has one, and the calendar year
     * otherwise. A dashboard that refused to render until periods existed
     * would be unreachable on a school's first day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function currentRange(CarbonImmutable $today): array
    {
        $fiscalYear = $this->covering($today);

        if ($fiscalYear !== null) {
            $range = $this->range($fiscalYear);

            if ($range !== null) {
                return $range;
            }
        }

        return [
            $today->startOfYear()->startOfDay(),
            $today->endOfYear()->startOfDay(),
        ];
    }
}
