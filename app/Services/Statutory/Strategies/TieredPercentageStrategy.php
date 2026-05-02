<?php

declare(strict_types=1);

namespace App\Services\Statutory\Strategies;

use App\Services\Statutory\StatutoryContributionResult;
use App\Services\Statutory\StatutoryContributionStrategy;
use App\ValueObjects\Money;
use InvalidArgumentException;

/**
 * Strategy for Pag-IBIG-style tiered percentages with an MSC cap.
 *
 * `rules` shape:
 * <code>
 * {
 *   "threshold": 150000,                                  // centavos
 *   "lower": {"ee_bp": 100, "er_bp": 200},                // basis points
 *   "upper": {"ee_bp": 200, "er_bp": 200},
 *   "max_msc": 500000                                     // centavos
 * }
 * </code>
 *
 * Algorithm:
 *   1. `cappedInput = min(input, max_msc)`
 *   2. If `cappedInput <= threshold` use `lower` rates, else use `upper`.
 *   3. `employeeShare = cappedInput × ee_bp / 10000`  (banker's rounded)
 *   4. `employerShare = cappedInput × er_bp / 10000`  (banker's rounded)
 *   5. `employerEcShare = 0`
 *   6. `taxableAmount = cappedInput`
 *
 * Threshold semantics: `<=` (the threshold itself uses lower rates). Pag-IBIG's
 * 1500 PHP threshold means salaries up to AND INCLUDING 1500 use the lower tier.
 */
final class TieredPercentageStrategy implements StatutoryContributionStrategy
{
    public function compute(Money $input, array $rules, array $context = []): StatutoryContributionResult
    {
        $maxMsc = Money::fromCentavos((int) $rules['max_msc']);
        $cappedInput = $input->greaterThan($maxMsc) ? $maxMsc : $input;

        $threshold = Money::fromCentavos((int) $rules['threshold']);
        $tier = $cappedInput->lessThanOrEqual($threshold)
            ? $rules['lower']
            : $rules['upper'];

        $employeeShare = $cappedInput
            ->times((int) $tier['ee_bp'])
            ->dividedBy(10_000);

        $employerShare = $cappedInput
            ->times((int) $tier['er_bp'])
            ->dividedBy(10_000);

        return new StatutoryContributionResult(
            employeeShare: $employeeShare,
            employerShare: $employerShare,
            employerEcShare: Money::zero(),
            taxableAmount: $cappedInput,
        );
    }

    public function validateRules(array $rules): void
    {
        foreach (['threshold', 'max_msc'] as $key) {
            if (! array_key_exists($key, $rules) || ! is_int($rules[$key]) || $rules[$key] < 0) {
                throw new InvalidArgumentException(
                    "TieredPercentageStrategy: rules.{$key} must be a non-negative int."
                );
            }
        }

        if ($rules['threshold'] > $rules['max_msc']) {
            throw new InvalidArgumentException(
                'TieredPercentageStrategy: rules.threshold must be <= rules.max_msc.'
            );
        }

        foreach (['lower', 'upper'] as $tier) {
            if (! isset($rules[$tier]) || ! is_array($rules[$tier])) {
                throw new InvalidArgumentException(
                    "TieredPercentageStrategy: rules.{$tier} must be an array."
                );
            }

            foreach (['ee_bp', 'er_bp'] as $key) {
                if (! array_key_exists($key, $rules[$tier]) || ! is_int($rules[$tier][$key]) || $rules[$tier][$key] < 0) {
                    throw new InvalidArgumentException(
                        "TieredPercentageStrategy: rules.{$tier}.{$key} must be a non-negative int."
                    );
                }
            }
        }
    }
}
