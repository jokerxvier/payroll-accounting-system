<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Pas\EmployeeDeduction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an update to an existing per-employee allowance subscription.
 *
 * Same shape as the store request — the catalog-FK / amount / schedule /
 * date triplet is mutable. Authorization happens in the controller via
 * Gate::authorize('update', $employeeAllowance).
 */
final class EmployeeAllowanceUpdateRequest extends FormRequest
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
            'allowance_id' => ['required', 'integer', Rule::exists('pas_allowances', 'id')],
            'amount_centavos' => ['required', 'integer', 'min:1'],
            'schedule' => ['required', Rule::in(EmployeeDeduction::SCHEDULES)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
