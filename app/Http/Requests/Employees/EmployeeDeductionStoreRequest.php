<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeDeduction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new per-employee deduction subscription (Week 7, Chunk 6).
 *
 * Authorization is handled in the controller via Gate::authorize against
 * EmployeeDeductionPolicy + EmployeeProfilePolicy — this request always
 * returns true so the policy stays the single source of truth.
 *
 * Cross-field rule: exactly one of `amount_centavos` / `percent_basis_points`
 * is populated, matched against the resolved DeductionType's calc_method.
 * Sent as `withValidator` so the resolved type is available without
 * re-querying. The DB layer permits both nullable but the application invariant
 * is "one and only one".
 */
final class EmployeeDeductionStoreRequest extends FormRequest
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
            'deduction_type_id' => ['required', 'integer', Rule::exists('pas_deduction_types', 'id')],
            'amount_centavos' => ['nullable', 'integer', 'min:1'],
            'percent_basis_points' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'schedule' => ['required', Rule::in(EmployeeDeduction::SCHEDULES)],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $typeId = $this->input('deduction_type_id');
            if ($typeId === null) {
                return;
            }

            /** @var DeductionType|null $type */
            $type = DeductionType::query()->whereKey($typeId)->first();
            if ($type === null) {
                return;
            }

            $amount = $this->input('amount_centavos');
            $percent = $this->input('percent_basis_points');

            if ($type->calc_method === DeductionType::CALC_FIXED) {
                if ($amount === null || $amount === '') {
                    $v->errors()->add('amount_centavos', 'Amount is required for fixed-amount deductions.');
                }
                if ($percent !== null && $percent !== '') {
                    $v->errors()->add('percent_basis_points', 'Percent must be empty for fixed-amount deductions.');
                }
            }

            if ($type->calc_method === DeductionType::CALC_PERCENT) {
                if ($percent === null || $percent === '') {
                    $v->errors()->add('percent_basis_points', 'Percent is required for percent-based deductions.');
                }
                if ($amount !== null && $amount !== '') {
                    $v->errors()->add('amount_centavos', 'Amount must be empty for percent-based deductions.');
                }
            }
        });
    }
}
