<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Pas\EmployeeProfile;
use App\Services\Statutory\StatutoryContributionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validates payroll profile mutations for both the bulk-edit Edit sheet and
 * inline single-field PATCH paths. Every field is `sometimes` so partial
 * updates only validate what was actually submitted.
 *
 * Authorization happens in the controller (`Gate::authorize('update', $profile)`)
 * because the profile is resolved from the route's `staffId` and the policy
 * needs the resolved model. Returning `true` here keeps the policy as the
 * single source of truth and avoids re-resolving the profile inside this
 * FormRequest.
 */
class EmployeeProfileUpdateRequest extends FormRequest
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
            'basic_salary_centavos' => ['sometimes', 'integer', 'min:0', 'max:999999999999'],
            'employment_classification' => ['sometimes', 'string', Rule::in(EmployeeProfile::EMPLOYMENT_CLASSIFICATIONS)],
            'pay_frequency' => ['sometimes', 'string', Rule::in(['monthly', 'semi_monthly'])],
            'tax_status' => ['sometimes', 'nullable', 'string', 'max:8'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'sss_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'philhealth_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'pagibig_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bank_account_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'bank_account_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'date_hired' => ['sometimes', 'nullable', 'date'],
            'date_terminated' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'exempted_contribution_codes' => ['sometimes', 'array'],
            'exempted_contribution_codes.*' => ['string', Rule::in($this->activeContributionCodes())],
        ];
    }

    /**
     * Coerce explicit `null` to `[]` so a partial update that clears the field
     * persists as an empty exemption list rather than nulling the column.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('exempted_contribution_codes') && $this->input('exempted_contribution_codes') === null) {
            $this->merge(['exempted_contribution_codes' => []]);
        }
    }

    /**
     * Codes currently active in the statutory-contribution registry. Used as
     * the allowlist for per-employee exemptions so admins can't select a
     * contribution that doesn't apply to anyone today.
     *
     * @return list<string>
     */
    private function activeContributionCodes(): array
    {
        /** @var list<string> $codes */
        $codes = app(StatutoryContributionRegistry::class)
            ->active(Carbon::now())
            ->pluck('contribution_code')
            ->all();

        return $codes;
    }
}
