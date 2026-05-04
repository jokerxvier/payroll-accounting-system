<?php

declare(strict_types=1);

namespace App\Services\Statutory\Strategies;

use App\Services\Statutory\StatutoryContributionResult;
use App\Services\Statutory\StatutoryContributionStrategy;
use App\ValueObjects\Money;
use InvalidArgumentException;

/**
 * Strategy for flat-rate contributions: a single percentage applied to the
 * input salary (optionally clamped to a ceiling), then split between employee
 * and employer.
 *
 * Distinct from {@see PercentageWithCapStrategy} in two ways:
 *   - There is NO floor — inputs below any threshold pass through untouched.
 *   - The cap is OPTIONAL (`null` means no cap).
 *
 * Useful for contribution schemes that don't carry the PhilHealth-style
 * `[floor, ceiling]` clamp — e.g., flat surcharges, simple cooperative dues,
 * or future statutory schemes that publish only a single rate and an optional
 * salary ceiling.
 *
 * `rules` shape:
 * <code>
 * {
 *   "rate_bp": 1000,                  // basis points: 1000 = 10%
 *   "employee_share_bp": 5000,        // basis points; sums with employer to 10000
 *   "employer_share_bp": 5000,
 *   "cap": 5000000                    // centavos, OR null for no cap
 * }
 * </code>
 *
 * Algorithm:
 *   1. `cappedInput = (cap !== null && input > cap) ? cap : input`
 *   2. `total = cappedInput × rate_bp / 10000`              (banker's rounded)
 *   3. `employeeShare = total × employee_share_bp / 10000`  (banker's rounded)
 *   4. `employerShare = total - employeeShare`              (so cents always sum exactly)
 *   5. `employerEcShare = 0`
 *   6. `taxableAmount = cappedInput`
 *
 * Step 4 explicitly computes the employer share by subtraction, NOT by a
 * second `total × employer_share_bp / 10000`. That keeps the cent total exact
 * even on odd-cent splits — a 5-centavo total split 50/50 yields 2 / 3,
 * never 2 / 2 (which would silently lose a centavo).
 */
final class FlatPercentageStrategy implements StatutoryContributionStrategy
{
    public function compute(Money $input, array $rules, array $context = []): StatutoryContributionResult
    {
        $cap = $rules['cap'] ?? null;

        if ($cap !== null) {
            $capMoney = Money::fromCentavos((int) $cap);
            $cappedInput = $input->greaterThan($capMoney) ? $capMoney : $input;
        } else {
            $cappedInput = $input;
        }

        $total = $cappedInput
            ->times((int) $rules['rate_bp'])
            ->dividedBy(10_000);

        $employeeShare = $total
            ->times((int) $rules['employee_share_bp'])
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
        if (! array_key_exists('rate_bp', $rules) || ! is_int($rules['rate_bp'])) {
            throw new InvalidArgumentException(
                'FlatPercentageStrategy: rules.rate_bp must be an int.'
            );
        }

        if ($rules['rate_bp'] < 1 || $rules['rate_bp'] > 10_000) {
            throw new InvalidArgumentException(
                'FlatPercentageStrategy: rules.rate_bp must be between 1 and 10000 basis points (inclusive).'
            );
        }

        foreach (['employee_share_bp', 'employer_share_bp'] as $key) {
            if (! array_key_exists($key, $rules) || ! is_int($rules[$key]) || $rules[$key] < 0) {
                throw new InvalidArgumentException(
                    "FlatPercentageStrategy: rules.{$key} must be a non-negative int."
                );
            }
        }

        if ($rules['employee_share_bp'] + $rules['employer_share_bp'] !== 10_000) {
            throw new InvalidArgumentException(
                'FlatPercentageStrategy: rules.employee_share_bp + rules.employer_share_bp must equal 10000.'
            );
        }

        if (! array_key_exists('cap', $rules)) {
            throw new InvalidArgumentException(
                'FlatPercentageStrategy: rules.cap must be present (use null for no cap).'
            );
        }

        if ($rules['cap'] !== null && (! is_int($rules['cap']) || $rules['cap'] < 0)) {
            throw new InvalidArgumentException(
                'FlatPercentageStrategy: rules.cap must be a non-negative int or null.'
            );
        }
    }
}
