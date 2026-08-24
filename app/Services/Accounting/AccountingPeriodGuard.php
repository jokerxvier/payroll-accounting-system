<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\ClosedAccountingPeriodException;
use App\Models\Pas\AccountingPeriod;
use Carbon\CarbonImmutable;

/**
 * The single gate every posting passes through.
 *
 * Resolves the accounting period covering a date and refuses if it is closed
 * or missing. Centralised deliberately: period locking is only worth
 * anything if there is exactly one way into the ledger, so Slice 3's payroll
 * posting and Slices 5-7's document posting all call this rather than
 * re-checking `status` themselves.
 *
 * Periods are guaranteed non-overlapping by AccountingPeriodRequest, so at
 * most one can cover a given date.
 */
final class AccountingPeriodGuard
{
    /**
     * The open period covering `$date`.
     *
     * @throws ClosedAccountingPeriodException When no period covers the date,
     *                                         or the one that does is closed.
     */
    public function resolveOpenPeriodFor(CarbonImmutable $date): AccountingPeriod
    {
        $period = AccountingPeriod::query()->covering($date)->first();

        if ($period === null) {
            throw ClosedAccountingPeriodException::forUncoveredDate($date);
        }

        if (! $period->isOpen()) {
            throw ClosedAccountingPeriodException::forPeriod($period);
        }

        return $period;
    }

    /**
     * Whether `$date` currently falls in an open period.
     *
     * For read paths that need to disable a control or explain why an action
     * is unavailable. Anything that actually writes must call
     * {@see self::resolveOpenPeriodFor()} instead, so the refusal happens at
     * the point of the write rather than at a check that could go stale.
     */
    public function isOpenOn(CarbonImmutable $date): bool
    {
        $period = AccountingPeriod::query()->covering($date)->first();

        return $period?->isOpen() ?? false;
    }
}
