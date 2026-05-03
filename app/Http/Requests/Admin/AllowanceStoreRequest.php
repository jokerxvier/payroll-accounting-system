<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new row for `pas_allowances`.
 *
 * Authorization happens in the controller via Gate::authorize('create', ...);
 * this request always returns true from authorize() so the policy stays the
 * single source of truth.
 *
 * Conditional invariant (Decision 5 in the Week 7 plan):
 *   - When `is_de_minimis = true`, `de_minimis_cap_centavos` is REQUIRED
 *     and must be a positive integer (the cap is the centavos ceiling above
 *     which the excess becomes taxable).
 *   - When `is_de_minimis = false`, the cap may be NULL and is ignored at
 *     compute time.
 * Rule::requiredIf evaluates the boolean live so the form can flip between
 * the two modes without losing the field's nullable-when-off semantics.
 */
final class AllowanceStoreRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32', Rule::unique('pas_allowances', 'code')],
            'name' => ['required', 'string', 'max:120'],
            'is_taxable' => ['required', 'boolean'],
            'is_de_minimis' => ['required', 'boolean'],
            'de_minimis_cap_centavos' => [
                Rule::requiredIf(fn (): bool => $this->boolean('is_de_minimis')),
                'nullable',
                'integer',
                'min:1',
            ],
            'default_amount_centavos' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
