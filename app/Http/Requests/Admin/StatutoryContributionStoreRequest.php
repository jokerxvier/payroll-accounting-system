<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Pas\StatutoryContribution;
use App\Services\Statutory\StatutoryContributionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;

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
 */
final class StatutoryContributionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'contribution_code' => ['required', 'string', Rule::in(StatutoryContribution::CODES)],
            'label' => ['required', 'string', 'max:120'],
            'algorithm' => ['required', 'string', Rule::in(StatutoryContribution::ALGORITHMS)],
            'effective_from' => ['required', 'date'],
            'rules' => ['required', 'array'],
            'notes' => ['nullable', 'string'],
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

    /**
     * Delegate `rules` shape validation to the strategy registered for the
     * chosen algorithm. The strategy throws InvalidArgumentException with a
     * specific message; we surface that as a Laravel validation error on the
     * `rules` field.
     */
    private function validateRulesShapeForAlgorithm(Validator $v): void
    {
        $algorithm = (string) $this->input('algorithm');

        /** @var array<string, mixed> $rules */
        $rules = (array) $this->input('rules', []);

        /** @var StatutoryContributionResolver $resolver */
        $resolver = app(StatutoryContributionResolver::class);

        try {
            $strategy = $resolver->getStrategy($algorithm);
        } catch (LogicException) {
            // The Rule::in(ALGORITHMS) check should already have rejected this,
            // but guard the call anyway so a future enum drift doesn't crash.
            $v->errors()->add('algorithm', "Unknown algorithm '{$algorithm}'.");

            return;
        }

        try {
            $strategy->validateRules($rules);
        } catch (InvalidArgumentException $e) {
            $v->errors()->add('rules', $e->getMessage());
        }
    }
}
