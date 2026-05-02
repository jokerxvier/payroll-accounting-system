<?php

declare(strict_types=1);

namespace App\Services\Statutory\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Thrown when a payroll computation requests a contribution code on a date
 * for which no `pas_statutory_contributions` row is effective.
 *
 * This is a domain error, not a programming error: it means the rate table
 * has a hole. The expected production cause is a misconfiguration — either
 * the seeder has not run for the active environment, or super-admin has not
 * yet inserted a row covering the requested date.
 *
 * Either way, the right response is to fail loudly. Silently returning zero
 * would understate statutory deductions and corrupt downstream payroll.
 */
final class NoEffectiveContributionException extends RuntimeException
{
    /**
     * Build an exception describing which contribution code was queried
     * and for which date no effective row exists.
     */
    public static function for(string $contributionCode, CarbonInterface $date): self
    {
        return new self(sprintf(
            "No effective StatutoryContribution for code '%s' on date %s",
            $contributionCode,
            $date->toDateString(),
        ));
    }
}
