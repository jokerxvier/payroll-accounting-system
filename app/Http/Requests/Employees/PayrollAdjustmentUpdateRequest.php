<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Pas\PayrollAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an update to an existing one-off payroll adjustment.
 *
 * Same shape as the store request — every editable field is the same since
 * an adjustment is a self-contained line. Authorization happens in the
 * controller via Gate::authorize('update', $payrollAdjustment).
 *
 * `applied_pay_period_id` remains server-controlled (the Week 9 batch path
 * is the only writer), so it is excluded from this validation set.
 */
final class PayrollAdjustmentUpdateRequest extends FormRequest
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
            'kind' => ['required', Rule::in(PayrollAdjustment::KINDS)],
            'is_taxable' => ['required', 'boolean'],
            'amount_centavos' => ['required', 'integer', 'min:1'],
            'applies_on' => ['required', 'date'],
            'label' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
