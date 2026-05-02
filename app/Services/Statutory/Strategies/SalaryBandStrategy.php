<?php

declare(strict_types=1);

namespace App\Services\Statutory\Strategies;

use App\Services\Statutory\StatutoryContributionResult;
use App\Services\Statutory\StatutoryContributionStrategy;
use App\ValueObjects\Money;
use InvalidArgumentException;

/**
 * Strategy for SSS-style pre-computed salary bands.
 *
 * `rules` shape:
 * <code>
 * {
 *   "bands": [
 *     {
 *       "lower",
 *       "upper",
 *       "contribution_base",
 *       "employee_share",
 *       "employer_share",
 *       "employer_aux_share",
 *       "employee_aux_share"
 *     },
 *     ...
 *   ]
 * }
 * </code>
 *
 * Every value is an integer (centavos for money fields, `null` upper for the
 * top band). Bands are pre-computed: each row stores fixed peso amounts, so
 * no rate × salary multiplication happens here — lookup is a band-find.
 *
 * Lookup semantics:
 *   - lower-inclusive, upper-exclusive.
 *   - Top band (`upper === null`) catches everything from its lower bound upward.
 *   - Salary BELOW the lowest band's lower defaults to the lowest band — SSS
 *     enforces a statutory minimum MSC, so an under-minimum-wage employee still
 *     contributes at the floor.
 *
 * Result mapping:
 *   - `employeeShare      = employee_share + employee_aux_share` (regular + WISP/MPF)
 *   - `employerShare      = employer_share`
 *   - `employerEcShare    = employer_aux_share`                  (Employees' Compensation)
 *   - `taxableAmount      = contribution_base`                    (the MSC, not the input)
 */
final class SalaryBandStrategy implements StatutoryContributionStrategy
{
    public function compute(Money $input, array $rules, array $context = []): StatutoryContributionResult
    {
        /** @var list<array<string, int|null>> $bands */
        $bands = $rules['bands'];

        $band = $this->findBandFor($bands, $input);

        $employeeShare = Money::fromCentavos((int) $band['employee_share'])
            ->plus(Money::fromCentavos((int) $band['employee_aux_share']));

        $employerShare = Money::fromCentavos((int) $band['employer_share']);
        $employerEcShare = Money::fromCentavos((int) $band['employer_aux_share']);
        $taxableAmount = Money::fromCentavos((int) $band['contribution_base']);

        return new StatutoryContributionResult(
            employeeShare: $employeeShare,
            employerShare: $employerShare,
            employerEcShare: $employerEcShare,
            taxableAmount: $taxableAmount,
        );
    }

    public function validateRules(array $rules): void
    {
        if (! isset($rules['bands']) || ! is_array($rules['bands']) || $rules['bands'] === []) {
            throw new InvalidArgumentException(
                'SalaryBandStrategy: rules.bands must be a non-empty array.'
            );
        }

        $required = [
            'lower',
            'contribution_base',
            'employee_share',
            'employer_share',
            'employer_aux_share',
            'employee_aux_share',
        ];

        $previousLower = null;

        foreach ($rules['bands'] as $i => $band) {
            if (! is_array($band)) {
                throw new InvalidArgumentException(
                    "SalaryBandStrategy: bands[{$i}] must be an array."
                );
            }

            foreach ($required as $key) {
                if (! array_key_exists($key, $band) || ! is_int($band[$key])) {
                    throw new InvalidArgumentException(
                        "SalaryBandStrategy: bands[{$i}].{$key} must be an int."
                    );
                }
            }

            if (! array_key_exists('upper', $band) || ($band['upper'] !== null && ! is_int($band['upper']))) {
                throw new InvalidArgumentException(
                    "SalaryBandStrategy: bands[{$i}].upper must be an int or null."
                );
            }

            if ($previousLower !== null && $band['lower'] <= $previousLower) {
                throw new InvalidArgumentException(
                    'SalaryBandStrategy: bands must be sorted ascending by lower.'
                );
            }

            $previousLower = $band['lower'];
        }
    }

    /**
     * Find the band that owns `$input`. Below the lowest band, default to
     * the lowest (SSS minimum-MSC behavior).
     *
     * @param  list<array<string, int|null>>  $bands
     * @return array<string, int|null>
     */
    private function findBandFor(array $bands, Money $input): array
    {
        $value = $input->centavos();

        if ($value < (int) $bands[0]['lower']) {
            return $bands[0];
        }

        foreach ($bands as $band) {
            $lower = (int) $band['lower'];
            $upper = $band['upper'];

            if ($upper === null) {
                if ($value >= $lower) {
                    return $band;
                }

                continue;
            }

            if ($value >= $lower && $value < (int) $upper) {
                return $band;
            }
        }

        // Defensive: a contiguous band table cannot reach here.
        return $bands[count($bands) - 1];
    }
}
