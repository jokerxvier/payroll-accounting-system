<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Pas\DeductionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validates an update to an existing `pas_deduction_types` row.
 *
 * Authorization happens in the controller via Gate::authorize('update', $row);
 * this request always returns true from authorize() so the policy stays the
 * single source of truth.
 *
 * The `code` uniqueness rule ignores the current row so resaving an unchanged
 * code does not fail validation. The route binding key is `deductionType`
 * (configured in routes/admin.php via parameters() to keep the conventional
 * camelCase variable name even though the URI uses kebab-case).
 */
final class DeductionTypeUpdateRequest extends FormRequest
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
        /** @var DeductionType $deductionType */
        $deductionType = $this->route('deductionType');

        // Tenant-scoped uniqueness mirrors the composite (school_id, code)
        // index. Defensive fallback when no tenant is current.
        $tenantId = Tenant::current()?->getKey();

        $codeUniqueRule = Rule::unique('pas_deduction_types', 'code')->ignore($deductionType->id);
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
            'calc_method' => ['required', Rule::in(DeductionType::CALC_METHODS)],
            'source' => ['required', Rule::in(DeductionType::SOURCES)],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
