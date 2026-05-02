<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\StatutoryContributionResolver;
use App\Services\Statutory\StatutoryContributionResult;
use App\Services\Statutory\Strategies\BracketTableStrategy;
use App\ValueObjects\Money;
use App\ValueObjects\PayPeriodInput;

/**
 * Compute the BIR withholding tax for a single pay period.
 *
 * BIR is a {@see BracketTableStrategy}
 * algorithm: the rules JSON carries one bracket array per `period_type`
 * (`monthly`, `semi_monthly`). The strategy picks the right table based on
 * `context.period_type`; this action threads the period's frequency through
 * unchanged.
 *
 * Unlike SSS / PhilHealth / Pag-IBIG, BIR's brackets are already
 * period-specific — there is no halving step. The semi-monthly bracket
 * boundaries are roughly half the monthly ones in the seeded TRAIN-style
 * data, so the strategy handles the period-aware computation directly.
 *
 * BIR has no employer share, so the action returns only the employee share
 * as a {@see Money} — distinct from the contribution actions which return a
 * {@see StatutoryContributionResult}.
 *
 * The taxable income is supplied by the caller (the payroll computation
 * service in §6C); this action does not derive it.
 */
final class ComputeBirWithholdingTax
{
    public function __construct(private StatutoryContributionResolver $resolver) {}

    public function __invoke(Money $taxableIncome, PayPeriodInput $period): Money
    {
        $context = ['period_type' => $period->frequency()];

        $result = $this->resolver->compute(
            StatutoryContribution::CODE_BIR,
            $taxableIncome,
            $period->end(),
            $context,
        );

        return $result->employeeShare;
    }
}
