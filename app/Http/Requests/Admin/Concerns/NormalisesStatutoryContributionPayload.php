<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\StatutoryContributionResolver;
use App\Services\Statutory\Strategies\FlatPercentageStrategy;
use Illuminate\Contracts\Validation\Validator;
use InvalidArgumentException;
use LogicException;

/**
 * Shared payload normalisation + algorithm-shape validation for the
 * `pas_statutory_contributions` admin form requests.
 *
 * Both StatutoryContributionStoreRequest and StatutoryContributionUpdateRequest
 * accept the same dual-shape `flat_percentage` rules payload (decimal-percent
 * + decimal-pesos OR canonical *_bp + centavos) and apply the same
 * application_order / applies_to defaults. Pulling the helpers into a trait
 * keeps the two requests in lockstep — if the dual-shape contract evolves
 * we change it once.
 *
 * Why a trait and not a base class:
 *   FormRequest already extends Laravel's base, and we want to keep the
 *   subclasses concrete so PHPStan and IDEs see the full Request API. A trait
 *   composes the helpers in without altering the inheritance chain.
 *
 * Method visibility:
 *   - applyStatutoryContributionDefaults() is `protected` so each request can
 *     call it from its own prepareForValidation() override.
 *   - validateRulesShapeForAlgorithm() is `protected` so each request's
 *     withValidator() closure can call it.
 *   - The numeric conversion helpers stay `private` — they're internal to the
 *     normalisation flow and not part of the trait's public surface.
 */
trait NormalisesStatutoryContributionPayload
{
    /**
     * Default the application_order / applies_to columns when the caller
     * omits them, and normalise the decimal-percent shape of `rules` for
     * `flat_percentage` into the canonical basis-points shape that the
     * strategy speaks.
     *
     * Each FormRequest subclass calls this from its own
     * prepareForValidation() so the trait is opt-in per request — no
     * surprises if a future request wants different defaults.
     */
    protected function applyStatutoryContributionDefaults(): void
    {
        $merge = [];

        if (! $this->has('application_order')) {
            $merge['application_order'] = 100;
        }

        if (! $this->has('applies_to')) {
            $merge['applies_to'] = StatutoryContribution::APPLIES_TO_GROSS_PAY;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }

        if ((string) $this->input('algorithm') === StatutoryContribution::ALGORITHM_FLAT_PERCENTAGE) {
            $this->normaliseFlatPercentageRules();
        }
    }

    /**
     * Delegate `rules` shape validation to the strategy registered for the
     * chosen algorithm. The strategy throws InvalidArgumentException with a
     * specific message; we surface that as a Laravel validation error on
     * the `rules` field.
     */
    protected function validateRulesShapeForAlgorithm(Validator $v): void
    {
        $algorithm = (string) $this->input('algorithm');

        /** @var array<string, mixed> $rules */
        $rules = (array) $this->input('rules', []);

        /** @var StatutoryContributionResolver $resolver */
        $resolver = app(StatutoryContributionResolver::class);

        try {
            $strategy = $resolver->getStrategy($algorithm);
        } catch (LogicException) {
            // The Rule::in(ALGORITHMS) check should already have rejected
            // this, but guard the call so a future enum drift doesn't crash.
            $v->errors()->add('algorithm', "Unknown algorithm '{$algorithm}'.");

            return;
        }

        try {
            $strategy->validateRules($rules);
        } catch (InvalidArgumentException $e) {
            $v->errors()->add('rules', $e->getMessage());
        }
    }

    /**
     * Convert a decimal-percent / decimal-pesos `flat_percentage` rules
     * payload into the canonical integer-basis-points / centavos shape that
     * {@see FlatPercentageStrategy} speaks.
     * Already-canonical payloads are passed through unchanged so
     * backend-internal callers can submit *_bp directly.
     *
     * Conversion rules:
     *   - rate_percent ("1.5")             → rate_bp = round(percent × 100)
     *   - employee_share_percent ("50")    → employee_share_bp = percent × 100
     *   - employer_share_percent ("50")    → employer_share_bp = percent × 100
     *   - cap_amount ("50000.00")          → cap = pesos × 100 (centavos)
     *
     * No floats: bcmath does the arithmetic so 1.5% becomes exactly 150 bp,
     * never 149 or 151. Malformed numerics (`"abc"`, `"1,000"`) are left
     * untouched so the downstream strategy validator surfaces a typed error.
     */
    private function normaliseFlatPercentageRules(): void
    {
        /** @var array<string, mixed> $rules */
        $rules = (array) $this->input('rules', []);

        if (! array_key_exists('rate_bp', $rules) && array_key_exists('rate_percent', $rules)) {
            $bp = $this->percentToBasisPoints($rules['rate_percent']);

            if ($bp !== null) {
                $rules['rate_bp'] = $bp;
            }

            unset($rules['rate_percent']);
        }

        foreach (['employee_share' => 'employee_share_bp', 'employer_share' => 'employer_share_bp'] as $prefix => $bpKey) {
            $percentKey = $prefix.'_percent';

            if (! array_key_exists($bpKey, $rules) && array_key_exists($percentKey, $rules)) {
                $bp = $this->percentToBasisPoints($rules[$percentKey]);

                if ($bp !== null) {
                    $rules[$bpKey] = $bp;
                }

                unset($rules[$percentKey]);
            }
        }

        if (! array_key_exists('cap', $rules) && array_key_exists('cap_amount', $rules)) {
            $rules['cap'] = $this->pesosToCentavos($rules['cap_amount']);

            unset($rules['cap_amount']);
        }

        $this->merge(['rules' => $rules]);
    }

    /**
     * Parse a decimal-percent value (int, numeric string, or float-shaped
     * string like "1.5") into integer basis points. Returns null when the
     * input is not a usable numeric — the strategy validator will then
     * reject the missing key with its own message.
     *
     * Uses bcmath to avoid float-induced precision drift (e.g. 0.1 + 0.2).
     */
    private function percentToBasisPoints(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $stringValue = (string) $value;

        if (! is_numeric($stringValue)) {
            return null;
        }

        return (int) bcmul($stringValue, '100', 0);
    }

    /**
     * Parse a decimal-pesos value into integer centavos, or null when the
     * input is null. Non-numeric inputs return null so the downstream
     * strategy validator surfaces a typed error on the missing/wrong cap.
     */
    private function pesosToCentavos(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $stringValue = (string) $value;

        if (! is_numeric($stringValue)) {
            return null;
        }

        return (int) bcmul($stringValue, '100', 0);
    }
}
