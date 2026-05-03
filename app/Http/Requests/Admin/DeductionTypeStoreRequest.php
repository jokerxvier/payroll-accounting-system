<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Pas\DeductionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new row for `pas_deduction_types`.
 *
 * Authorization happens in the controller via Gate::authorize('create', ...);
 * this request always returns true from authorize() so the policy stays the
 * single source of truth — same pattern as StatutoryContributionStoreRequest.
 *
 * `code` uniqueness is also enforced at the DB layer (unique index in the
 * Chunk-1 migration); the rule here surfaces the conflict as a 422 field
 * error instead of a SQL exception.
 */
final class DeductionTypeStoreRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32', Rule::unique('pas_deduction_types', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'is_taxable' => ['required', 'boolean'],
            'calc_method' => ['required', Rule::in(DeductionType::CALC_METHODS)],
            'source' => ['required', Rule::in(DeductionType::SOURCES)],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
