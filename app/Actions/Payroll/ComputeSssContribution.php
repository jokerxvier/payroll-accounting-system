<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\StatutoryContributionResolver;
use App\Services\Statutory\StatutoryContributionResult;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Compute the SSS contribution for a single pay period.
 *
 * Delegates the band lookup and arithmetic to the SSS strategy via the
 * {@see StatutoryContributionResolver}. The contribution table that applies
 * to a given period is the row effective on the period's **end date** —
 * pay periods are atomic units, so a single rate set governs the entire
 * window even if a new table technically takes effect mid-period.
 *
 * For semi-monthly payrolls the SSS table itself is monthly, so the action
 * halves every Money field of the resolver's result. Banker's rounding via
 * {@see Money::dividedBy()} handles odd-centavo bands
 * cleanly without statistical bias across many employees.
 */
final class ComputeSssContribution
{
    public function __construct(private StatutoryContributionResolver $resolver) {}

    /**
     * @param  Money  $monthlyBasis  Monthly salary basis (the
     *                               `basic_salary_centavos`-equivalent amount the SSS
     *                               table is keyed on, NOT the per-period basic earning).
     */
    public function __invoke(Money $monthlyBasis, PayPeriodInput $period): StatutoryContributionResult
    {
        $result = $this->resolver->compute(
            StatutoryContribution::CODE_SSS,
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
