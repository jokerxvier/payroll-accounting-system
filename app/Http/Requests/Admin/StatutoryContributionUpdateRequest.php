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
 * Validates an in-place update to an existing `pas_statutory_contributions`
 * row.
 *
 * Authorization is enforced here (rather than in the controller) so the
 * `update` policy gate — which combines the super-admin role check with
 * {@see StatutoryContribution::isEditable()} — runs before validation. A
 * non-editable row therefore short-circuits to 403 without ever exercising
 * the rules() pipeline.
 *
 * Three invariants over and above the StoreRequest:
 *   1. `contribution_code` MUST equal the route-model's existing code.
 *      Renaming a code mid-life would orphan all prior versions and silently
 *      break the registry's per-code chronology. We surface the attempt as a
 *      422 instead of silently coercing it.
 *   2. `effective_from` chronology check excludes the row being edited from
 *      the `max(effective_from)` lookup. Otherwise a no-op save would fail
 *      its own chronology guard ("must be after itself"). Voided / past
 *      siblings still participate, matching StoreRequest semantics.
 *   3. The same dual-shape `rules` payload contract as StoreRequest applies
 *      via {@see NormalisesStatutoryContributionPayload}.
 */
final class StatutoryContributionUpdateRequest extends FormRequest
{
    use NormalisesStatutoryContributionPayload;

    public function authorize(): bool
    {
        $row = $this->route('contribution');

        if (! $row instanceof StatutoryContribution) {
            return false;
        }

        return $this->user()?->can('update', $row) ?? false;
    }

    /**
     * Reuse the same defaulting + flat_percentage normalisation as Store so
     * a callable browser form may submit decimal-percent values without
     * crafting basis points by hand.
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
        /** @var StatutoryContribution $row */
        $row = $this->route('contribution');

        return [
            // Lock contribution_code to the route-model's existing value so
            // any drift surfaces as an actionable 422 rather than a silent
            // coercion. The unchanged code still needs to be submitted so
            // the form can round-trip a complete payload.
            'contribution_code' => [
                'required',
                'string',
                Rule::in([$row->contribution_code]),
            ],
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
        /** @var StatutoryContribution $row */
        $row = $this->route('contribution');

        return [
            'application_order.integer' => 'application_order must be an integer (use 100 to follow the seeded PH set).',
            'applies_to.in' => 'applies_to must be one of: gross_pay, taxable_income.',
            'contribution_code.in' => "contribution_code cannot change. Submit '{$row->contribution_code}' or void this row and create a new code.",
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->validateEffectiveFromIsForwardExcludingSelf($v);

            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->validateRulesShapeForAlgorithm($v);
        });
    }

    /**
     * Same chronology guard as StoreRequest, but excluding the row being
     * edited so a save that doesn't move `effective_from` doesn't trip
     * "must be strictly after the latest" by comparing against itself.
     *
     * Voided siblings still participate in `max(effective_from)` (matching
     * StoreRequest semantics) — a void hides a row from payroll math but
     * not from chronology, since the historical date still exists in the
     * timeline.
     */
    private function validateEffectiveFromIsForwardExcludingSelf(Validator $v): void
    {
        /** @var StatutoryContribution $row */
        $row = $this->route('contribution');

        $newDate = CarbonImmutable::parse((string) $this->input('effective_from'));

        $maxExisting = StatutoryContribution::query()
            ->where('contribution_code', $row->contribution_code)
            ->where('id', '!=', $row->id)
            ->max('effective_from');

        if ($maxExisting === null) {
            return;
        }

        $maxExistingDate = CarbonImmutable::parse((string) $maxExisting);

        if ($newDate->lessThanOrEqualTo($maxExistingDate)) {
            $v->errors()->add(
                'effective_from',
                "effective_from must be after {$maxExistingDate->toDateString()} (the latest other version for {$row->contribution_code}).",
            );
        }
    }
}
