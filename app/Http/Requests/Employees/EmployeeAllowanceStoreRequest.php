<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Pas\EmployeeDeduction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new per-employee allowance subscription (Week 7, Chunk 6).
 *
 * Allowances are always fixed-amount (the catalog does not carry a percent
 * variant), so no cross-field rule is needed — `amount_centavos` is plainly
 * required and positive. Authorization happens in the controller.
 */
final class EmployeeAllowanceStoreRequest extends FormRequest
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
            // The schedule enum is shared with EmployeeDeduction; both subscription
            // tables draw from the same SCHEDULES constant so the matrix lives in
            // exactly one place (see EmployeeAllowance::scopeForSchedule()).
            'schedule' => ['required', Rule::in(EmployeeDeduction::SCHEDULES)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
