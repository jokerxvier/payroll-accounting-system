<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\StatutoryContributionResolver;
use App\Services\Statutory\StatutoryContributionResult;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Compute the Pag-IBIG contribution for a single pay period.
 *
 * Same shape as the SSS / PhilHealth actions — defer to the resolver, halve
 * the result for semi-monthly runs because the underlying Pag-IBIG schedule
 * is monthly.
 *
 * Banker's rounding via {@see Money::dividedBy()} handles the halving.
 */
final class ComputePagibigContribution
{
    public function __construct(private StatutoryContributionResolver $resolver) {}

    public function __invoke(Money $monthlyBasis, PayPeriodInput $period): StatutoryContributionResult
    {
        $result = $this->resolver->compute(
            StatutoryContribution::CODE_PAGIBIG,
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
