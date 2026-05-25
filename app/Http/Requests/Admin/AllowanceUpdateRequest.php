<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Pas\Allowance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validates an update to an existing `pas_allowances` row.
 *
 * Authorization happens in the controller via Gate::authorize('update', $row);
 * this request always returns true from authorize() so the policy stays the
 * single source of truth.
 *
 * Same conditional invariant as the store request: `de_minimis_cap_centavos`
 * is REQUIRED when `is_de_minimis = true`, NULL otherwise (Decision 5).
 *
 * The `code` uniqueness rule ignores the current row so resaving an unchanged
 * code does not fail validation.
 */
final class AllowanceUpdateRequest extends FormRequest
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
        /** @var Allowance $allowance */
        $allowance = $this->route('allowance');

        // Tenant-scoped uniqueness mirrors the composite (school_id, code)
        // index added in Stage A. Defensive fallback: when no tenant is
        // current (console / bootstrap), drop the where clause so the rule
        // still functions (existing single-tenant behaviour).
        $tenantId = Tenant::current()?->getKey();

        $codeUniqueRule = Rule::unique('pas_allowances', 'code')->ignore($allowance->id);
        if ($tenantId !== null) {
            $codeUniqueRule = $codeUniqueRule->where('school_id', $tenantId);
        }

        return [
            'code' => [
                'required',
                'string',
                'max:32',
                $codeUniqueRule,
            ],
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
