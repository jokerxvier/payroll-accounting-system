<?php

declare(strict_types=1);

namespace App\Services\Statutory;

use App\Models\Pas\StatutoryContribution;
use App\Providers\AppServiceProvider;
use App\Services\Statutory\Exceptions\NoEffectiveContributionException;
use App\ValueObjects\Money;
use Carbon\CarbonInterface;
use LogicException;

/**
 * Front door for every statutory contribution computation.
 *
 * Given a contribution code, an input amount, an effective date, and an
 * algorithm-specific context, the resolver:
 *   1. Looks up the active `pas_statutory_contributions` row for that
 *      (code, date) pair via the model's `current()` helper.
 *   2. Throws {@see NoEffectiveContributionException} when the rate table
 *      has a hole — failing loud beats silently understating a deduction.
 *   3. Dispatches to the strategy whose key matches `row.algorithm` and
 *      returns its {@see StatutoryContributionResult}.
 *
 * The strategies map is injected via the service container; see
 * {@see AppServiceProvider} for the singleton binding.
 */
final class StatutoryContributionResolver
{
    /**
     * @param  array<string, StatutoryContributionStrategy>  $strategies
     *                                                                    Map keyed by `StatutoryContribution::ALGORITHM_*` constants.
     */
    public function __construct(
        private array $strategies,
    ) {}

    /**
     * Compute the contribution for a single (code, date, input) triple.
     *
     * @param  array<string, mixed>  $context  Algorithm-specific context
     *                                         (e.g. `['period_type' => 'monthly']` for `BracketTableStrategy`).
     *
     * @throws NoEffectiveContributionException When no row is effective on `$date`.
     * @throws LogicException When the row's `algorithm` is not registered.
     */
    public function compute(
        string $contributionCode,
        Money $input,
        CarbonInterface $date,
        array $context = [],
    ): StatutoryContributionResult {
        $row = StatutoryContribution::current($contributionCode, $date)
            ?? throw NoEffectiveContributionException::for($contributionCode, $date);

        return $this->getStrategy($row->algorithm)->compute($input, $row->rules, $context);
    }

    /**
     * Resolve the strategy registered for a given algorithm key.
     *
     * Used by the admin save path so the FormRequest can validate the
     * `rules` JSON shape against the algorithm before the row is persisted.
     *
     * @throws LogicException When no strategy is registered for the algorithm.
     */
    public function getStrategy(string $algorithm): StatutoryContributionStrategy
    {
        return $this->strategies[$algorithm]
            ?? throw new LogicException(
                "No strategy registered for algorithm '{$algorithm}'."
            );
    }
}
