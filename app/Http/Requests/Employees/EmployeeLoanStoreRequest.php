<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Pas\EmployeeDeduction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new per-employee loan (Week 7, Chunk 6).
 *
 * Allows the user to set principal, monthly amortization, schedule, started_on,
 * and notes. `outstanding_balance_centavos` is server-controlled — at create
 * time it is initialized to the principal in the controller, never accepted
 * from user input. `lock_version` is also server-controlled (initialized to 0).
 *
 * `closed_on` is omitted: a loan closes itself when its outstanding balance
 * hits zero (via `EmployeeLoan::applyAmortization()`), not by a manual UI
 * field.
 */
final class EmployeeLoanStoreRequest extends FormRequest
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
            'principal_centavos' => ['required', 'integer', 'min:1'],
            'monthly_amortization_centavos' => ['required', 'integer', 'min:1'],
            'schedule' => ['required', Rule::in(EmployeeDeduction::SCHEDULES)],
            'started_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
