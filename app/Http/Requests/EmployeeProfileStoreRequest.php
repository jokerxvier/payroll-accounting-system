<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Pas\EmployeeProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the create-time payload for a payroll profile from the
 * "Set up profile" affordance on /employees. Distinct from
 * {@see EmployeeProfileUpdateRequest} because at creation we require the
 * minimum fields needed for downstream payroll computation:
 *
 *   - basic_salary_centavos
 *   - employment_classification
 *   - pay_frequency
 *   - is_active
 *
 * Rules mirror the corresponding `sometimes` rules in
 * {@see EmployeeProfileUpdateRequest}; they are simply promoted to `required`
 * here so a freshly-created row never lands with the placeholder defaults
 * the migration would otherwise apply.
 */
class EmployeeProfileStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmployeeProfile::class) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'basic_salary_centavos' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'employment_classification' => ['required', 'string', Rule::in(EmployeeProfile::EMPLOYMENT_CLASSIFICATIONS)],
            'pay_frequency' => ['required', 'string', Rule::in(['monthly', 'semi_monthly'])],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
