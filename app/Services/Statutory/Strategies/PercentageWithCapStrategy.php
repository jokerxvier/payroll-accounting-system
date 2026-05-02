<?php

declare(strict_types=1);

namespace App\Services\Statutory\Strategies;

use App\Services\Statutory\StatutoryContributionResult;
use App\Services\Statutory\StatutoryContributionStrategy;
use App\ValueObjects\Money;
use InvalidArgumentException;

/**
 * Strategy for PhilHealth-style premiums: a flat rate against a salary base
 * that's clamped to a `[floor, ceiling]` range, then split between employee
 * and employer.
 *
 * `rules` shape:
 * <code>
 * {
 *   "rate_bp": 500,                 // basis points: 500 = 5%
 *   "floor": 1000000,               // centavos
 *   "ceiling": 10000000,            // centavos
 *   "split": {"employee_bp": 5000, "employer_bp": 5000}  // sum to exactly 10000
 * }
 * </code>
 *
 * Algorithm:
 *   1. `cappedInput = min(max(input, floor), ceiling)`
 *   2. `total = cappedInput × rate_bp / 10000`        (banker's rounded)
 *   3. `employeeShare = total × employee_bp / 10000`  (banker's rounded)
 *   4. `employerShare = total - employeeShare`        (so cents always sum exactly)
 *   5. `employerEcShare = 0`
 *   6. `taxableAmount = cappedInput`
 *
 * Step 4 explicitly computes the employer share by subtraction, NOT by a
 * second `total × employer_bp / 10000`. That keeps the cent total exact
 * even on odd-cent splits — a 5-centavo total split 50/50 yields 2 / 3,
 * never 2 / 2 (which would silently lose a centavo).
 */
final class PercentageWithCapStrategy implements StatutoryContributionStrategy
{
    public function compute(Money $input, array $rules, array $context = []): StatutoryContributionResult
    {
        $floor = Money::fromCentavos((int) $rules['floor']);
        $ceiling = Money::fromCentavos((int) $rules['ceiling']);

        $cappedInput = $input->lessThan($floor)
            ? $floor
            : ($input->greaterThan($ceiling) ? $ceiling : $input);

        $total = $cappedInput
            ->times((int) $rules['rate_bp'])
            ->dividedBy(10_000);

        $employeeShare = $total
            ->times((int) $rules['split']['employee_bp'])
            ->dividedBy(10_000);

        $employerShare = $total->minus($employeeShare);

        return new StatutoryContributionResult(
            employeeShare: $employeeShare,
            employerShare: $employerShare,
            employerEcShare: Money::zero(),
            taxableAmount: $cappedInput,
        );
    }

    public function validateRules(array $rules): void
    {
        foreach (['rate_bp', 'floor', 'ceiling'] as $key) {
            if (! array_key_exists($key, $rules) || ! is_int($rules[$key]) || $rules[$key] < 0) {
                throw new InvalidArgumentException(
                    "PercentageWithCapStrategy: rules.{$key} must be a non-negative int."
                );
            }
        }

        if ($rules['floor'] > $rules['ceiling']) {
            throw new InvalidArgumentException(
                'PercentageWithCapStrategy: rules.floor must be <= rules.ceiling.'
            );
        }

        if (! isset($rules['split']) || ! is_array($rules['split'])) {
            throw new InvalidArgumentException(
                'PercentageWithCapStrategy: rules.split must be an array.'
            );
        }

        foreach (['employee_bp', 'employer_bp'] as $key) {
            if (! array_key_exists($key, $rules['split']) || ! is_int($rules['split'][$key]) || $rules['split'][$key] < 0) {
                throw new InvalidArgumentException(
                    "PercentageWithCapStrategy: rules.split.{$key} must be a non-negative int."
                );
            }
        }

        if ($rules['split']['employee_bp'] + $rules['split']['employer_bp'] !== 10_000) {
            throw new InvalidArgumentException(
                'PercentageWithCapStrategy: rules.split.employee_bp + rules.split.employer_bp must equal 10000.'
            );
        }
    }
}
