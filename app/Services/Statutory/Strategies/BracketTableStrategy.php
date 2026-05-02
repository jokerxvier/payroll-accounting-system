<?php

declare(strict_types=1);

namespace App\Services\Statutory\Strategies;

use App\Services\Statutory\StatutoryContributionResult;
use App\Services\Statutory\StatutoryContributionStrategy;
use App\ValueObjects\Money;
use InvalidArgumentException;

/**
 * Strategy for BIR-style bracketed tax tables.
 *
 * `rules` shape:
 * <code>
 * {
 *   "period_types": {
 *     "monthly":      [{lower, upper, base_tax, excess_rate_bp, excess_over}, ...],
 *     "semi_monthly": [...],
 *     ...
 *   }
 * }
 * </code>
 *
 * Each bracket is an integer-only record:
 *   - lower, upper, base_tax, excess_over: centavos (`int`, `null` upper for top tier).
 *   - excess_rate_bp: basis points (1/10000) — `1500` means 15%.
 *
 * The caller MUST provide `context['period_type']` to select the bracket family.
 * Lookup semantics: lower-inclusive, upper-exclusive; the top tier (`upper === null`)
 * catches everything from its lower bound upward.
 *
 * Tax formula:
 *   `tax = base_tax + (input - excess_over) × (excess_rate_bp / 10000)`
 *
 * The basis-points division uses {@see Money::dividedBy()}, which applies banker's
 * rounding (round half to even) — the rule mandated by `CODING_STANDARDS_LARAVEL.md`
 * Section 4 for tax math.
 *
 * BIR has no employer share, so `employerShare` and `employerEcShare` are always
 * zero. `taxableAmount` is the input salary unmodified — this layer applies no
 * caps or floors.
 */
final class BracketTableStrategy implements StatutoryContributionStrategy
{
    public function compute(Money $input, array $rules, array $context = []): StatutoryContributionResult
    {
        if (! isset($context['period_type']) || ! is_string($context['period_type'])) {
            throw new InvalidArgumentException(
                'BracketTableStrategy requires a string `period_type` in $context.'
            );
        }

        $periodType = $context['period_type'];

        if (! isset($rules['period_types'][$periodType]) || ! is_array($rules['period_types'][$periodType])) {
            throw new InvalidArgumentException(
                "BracketTableStrategy: rules have no bracket array for period_type '{$periodType}'."
            );
        }

        /** @var list<array<string, int|null>> $brackets */
        $brackets = $rules['period_types'][$periodType];

        $bracket = $this->findBracketFor($brackets, $input);

        // tax = base_tax + (input - excess_over) × excess_rate_bp / 10000
        $baseTax = Money::fromCentavos((int) $bracket['base_tax']);
        $excessOver = Money::fromCentavos((int) $bracket['excess_over']);
        $excess = $input->minus($excessOver);

        $taxOnExcess = $excess
            ->times((int) $bracket['excess_rate_bp'])
            ->dividedBy(10_000);

        $tax = $baseTax->plus($taxOnExcess);

        return new StatutoryContributionResult(
            employeeShare: $tax,
            employerShare: Money::zero(),
            employerEcShare: Money::zero(),
            taxableAmount: $input,
        );
    }

    public function validateRules(array $rules): void
    {
        if (! isset($rules['period_types']) || ! is_array($rules['period_types']) || $rules['period_types'] === []) {
            throw new InvalidArgumentException(
                'BracketTableStrategy: rules.period_types must be a non-empty array.'
            );
        }

        foreach ($rules['period_types'] as $periodType => $brackets) {
            if (! is_string($periodType) || $periodType === '') {
                throw new InvalidArgumentException(
                    'BracketTableStrategy: every period_types key must be a non-empty string.'
                );
            }

            if (! is_array($brackets) || $brackets === []) {
                throw new InvalidArgumentException(
                    "BracketTableStrategy: period_types['{$periodType}'] must be a non-empty array of brackets."
                );
            }

            $previousLower = null;

            foreach ($brackets as $i => $bracket) {
                if (! is_array($bracket)) {
                    throw new InvalidArgumentException(
                        "BracketTableStrategy: period_types['{$periodType}'][{$i}] must be an array."
                    );
                }

                foreach (['lower', 'base_tax', 'excess_rate_bp', 'excess_over'] as $key) {
                    if (! array_key_exists($key, $bracket) || ! is_int($bracket[$key])) {
                        throw new InvalidArgumentException(
                            "BracketTableStrategy: period_types['{$periodType}'][{$i}].{$key} must be an int."
                        );
                    }
                }

                if (! array_key_exists('upper', $bracket) || ($bracket['upper'] !== null && ! is_int($bracket['upper']))) {
                    throw new InvalidArgumentException(
                        "BracketTableStrategy: period_types['{$periodType}'][{$i}].upper must be an int or null."
                    );
                }

                if ($i === 0 && $bracket['lower'] !== 0) {
                    throw new InvalidArgumentException(
                        "BracketTableStrategy: period_types['{$periodType}'] must start with lower=0."
                    );
                }

                if ($previousLower !== null && $bracket['lower'] <= $previousLower) {
                    throw new InvalidArgumentException(
                        "BracketTableStrategy: period_types['{$periodType}'] brackets must be sorted ascending by lower."
                    );
                }

                $previousLower = $bracket['lower'];
            }
        }
    }

    /**
     * Walk the ordered bracket list and return the row that owns `$input`.
     *
     * Lower bound is inclusive, upper bound is exclusive. Top tier
     * (`upper === null`) catches everything from its lower bound upward.
     *
     * Falls back to the first bracket when input is below every lower bound
     * (defensive — the lowest bracket is expected to start at zero).
     *
     * @param  list<array<string, int|null>>  $brackets
     * @return array<string, int|null>
     */
    private function findBracketFor(array $brackets, Money $input): array
    {
        $value = $input->centavos();

        foreach ($brackets as $bracket) {
            $lower = (int) $bracket['lower'];
            $upper = $bracket['upper'];

            if ($upper === null) {
                if ($value >= $lower) {
                    return $bracket;
                }

                continue;
            }

            if ($value >= $lower && $value < (int) $upper) {
                return $bracket;
            }
        }

        return $brackets[0];
    }
}
