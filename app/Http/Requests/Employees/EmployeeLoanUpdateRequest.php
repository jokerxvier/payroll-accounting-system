<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Pas\EmployeeDeduction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an update to an existing per-employee loan.
 *
 * Editable fields: code, monthly_amortization, schedule, notes.
 *
 * Intentionally NOT editable from the UI:
 *   - `principal_centavos` — historical record of the original loan amount.
 *     Changing it would corrupt the (principal − outstanding) progress
 *     computation. A "loan refinance" is modelled as closing the existing
 *     loan + opening a new one.
 *   - `outstanding_balance_centavos` — mutated only by
 *     `EmployeeLoan::applyAmortization()` inside the Week-9 batch transaction.
 *     Manual edits would race the persistence path's `SELECT ... FOR UPDATE`
 *     and break the lock_version optimistic-lock contract.
 *   - `lock_version` — server-managed counter for the optimistic lock.
 *   - `started_on` / `closed_on` — these are lifecycle markers, not inputs.
 *
 * The controller drops any of these keys before calling `update()` even if
 * a malicious client sends them — defense in depth on top of this request.
 */
final class EmployeeLoanUpdateRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:64'],
            'monthly_amortization_centavos' => ['required', 'integer', 'min:1'],
            'schedule' => ['required', Rule::in(EmployeeDeduction::SCHEDULES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
