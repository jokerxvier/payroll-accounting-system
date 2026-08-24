<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Pas\AccountingPeriod;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Thrown when something tries to post into a period that is closed, or into
 * a date no open period covers.
 *
 * A closed period is immutable (`rules/CODING_STANDARDS_LARAVEL.md` §434):
 * once the books are closed the figures have been reported, and letting a
 * late entry slip in would change a number someone has already acted on.
 * Corrections go into an open period as a reversing entry instead.
 */
final class ClosedAccountingPeriodException extends DomainException
{
    public static function forPeriod(AccountingPeriod $period): self
    {
        return new self(sprintf(
            "Accounting period '%s' (%s to %s) is closed and cannot receive new entries. Post a reversing entry in an open period instead.",
            $period->code,
            $period->start_date->toDateString(),
            $period->end_date->toDateString(),
        ));
    }

    public static function forUncoveredDate(CarbonImmutable $date): self
    {
        return new self(sprintf(
            'No accounting period covers %s. Create a period for that date before posting into it.',
            $date->toDateString(),
        ));
    }
}
