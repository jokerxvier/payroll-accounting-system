<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\StatutoryContributionResolver;
use App\Services\Statutory\StatutoryContributionResult;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Compute the PhilHealth contribution for a single pay period.
 *
 * Mirrors the structure of {@see ComputeSssContribution}: defer to the
 * {@see StatutoryContributionResolver} for the rate table effective on the
 * period's **end date**, then halve the result for a semi-monthly run since
 * the PhilHealth premium tables are monthly.
 *
 * Banker's rounding via {@see Money::dividedBy()} keeps odd-centavo halves
 * unbiased across batches.
 */
final class ComputePhilhealthContribution
{
    public function __construct(private StatutoryContributionResolver $resolver) {}

    public function __invoke(Money $monthlyBasis, PayPeriodInput $period): StatutoryContributionResult
    {
        $result = $this->resolver->compute(
            StatutoryContribution::CODE_PHILHEALTH,
            $monthlyBasis,
            $period->end(),
        );

        if (! $period->isSemiMonthly()) {
            return $result;
        }

        return new StatutoryContributionResult(
            employeeShare: $result->employeeShare->dividedBy(2),
            employerShare: $result->employerShare->dividedBy(2),
            employerEcShare: $result->employerEcShare->dividedBy(2),
            taxableAmount: $result->taxableAmount->dividedBy(2),
        );
    }
}
