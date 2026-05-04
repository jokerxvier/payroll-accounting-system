<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\NormalisesStatutoryContributionPayload;
use App\Models\Pas\StatutoryContribution;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new versioned row for `pas_statutory_contributions`.
 *
 * Authorization happens in the controller via Gate::authorize('create', ...);
 * this request always returns true from authorize() so the policy stays the
 * single source of truth.
 *
 * Two supersession invariants are enforced in withValidator():
 *   1. effective_from MUST be strictly greater than the latest existing
 *      effective_from for the same contribution_code. Tying or going backwards
 *      breaks the supersession invariant (outgoing.effective_to ==
 *      incoming.effective_from) that scopeForDate relies on.
 *   2. The `rules` JSON shape must satisfy the chosen algorithm's strategy
 *      (delegated to StatutoryContributionStrategy::validateRules so the
 *      shape rules live next to the strategy's compute logic).
 *
 * Dual-shape payload for `flat_percentage`:
 *   The strategy class internally always speaks basis points (rate_bp,
 *   employee_share_bp, employer_share_bp) and centavos (cap). The HTTP API
 *   accepts EITHER that integer-bp shape (for backend-internal callers and
 *   tests) OR a friendlier decimal-percent shape (rate_percent,
 *   employee_share_percent, employer_share_percent) plus `cap_amount` in
 *   pesos as a decimal string. prepareForValidation() converts the latter to
 *   the former before strategy validation runs, so persisted JSON is always
 *   in the canonical *_bp form. This keeps the storage shape uniform while
 *   making the surface easy to drive from both a browser form and a
 *   programmatic test.
 *
 * Defaulting + dual-shape normalisation + algorithm-shape validation are
 * delegated to {@see NormalisesStatutoryContributionPayload}, which is
 * shared with {@see StatutoryContributionUpdateRequest}.
 */
final class StatutoryContributionStoreRequest extends FormRequest
{
    use NormalisesStatutoryContributionPayload;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Default the new application_order / applies_to columns introduced by
     * the 2026_05_05 migration when the caller omits them, and normalise
     * the decimal-percent shape of `rules` for `flat_percentage` into the
     * canonical basis-points shape that the strategy understands.
     */
    protected function prepareForValidation(): void
    {
        $this->applyStatutoryContributionDefaults();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'contribution_code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{2,31}$/'],
            'label' => ['required', 'string', 'max:120'],
            'algorithm' => ['required', 'string', Rule::in(StatutoryContribution::ALGORITHMS)],
            'application_order' => ['required', 'integer', 'min:0'],
            'applies_to' => ['required', 'string', Rule::in(StatutoryContribution::APPLIES_TO)],
            'effective_from' => ['required', 'date'],
            'rules' => ['required', 'array'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'application_order.integer' => 'application_order must be an integer (use 100 to follow the seeded PH set).',
            'applies_to.in' => 'applies_to must be one of: gross_pay, taxable_income.',
            'contribution_code.regex' => 'Code must be uppercase letters, digits, or underscores (3–32 chars, must start with a letter). Example: CITY_TAX or MAKATI_LOCAL_TAX.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Skip cross-field checks if the basic rules already failed.
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->validateEffectiveFromIsForward($v);

            // Re-check before strategy validation: the date check above may
            // have added an error.
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->validateRulesShapeForAlgorithm($v);
        });
    }

    /**
     * Reject `effective_from` values that are not strictly after the latest
     * existing version for the same code. The first version of a code (no
     * existing rows) is always allowed.
     */
    private function validateEffectiveFromIsForward(Validator $v): void
    {
        $code = (string) $this->input('contribution_code');
        $newDate = CarbonImmutable::parse((string) $this->input('effective_from'));

        $maxExisting = StatutoryContribution::query()
            ->where('contribution_code', $code)
            ->max('effective_from');

        if ($maxExisting === null) {
            return;
        }

        $maxExistingDate = CarbonImmutable::parse((string) $maxExisting);

        if ($newDate->lessThanOrEqualTo($maxExistingDate)) {
            $v->errors()->add(
                'effective_from',
                "effective_from must be after {$maxExistingDate->toDateString()} (the latest existing version for {$code}).",
            );
        }
    }
}
