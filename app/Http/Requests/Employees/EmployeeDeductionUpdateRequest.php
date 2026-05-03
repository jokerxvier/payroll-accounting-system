<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeDeduction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an update to an existing per-employee deduction subscription.
 *
 * Authorization is handled in the controller; this request always returns
 * true. Same fixed-vs-percent invariant as the store request — the chosen
 * type's calc_method determines which field is required and which must be
 * empty.
 *
 * `deduction_type_id` may be reassigned in an update so HR can swap an
 * employee from one catalog row to another (e.g. when one type is being
 * retired and an active subscription needs to be migrated).
 */
final class EmployeeDeductionUpdateRequest extends FormRequest
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
