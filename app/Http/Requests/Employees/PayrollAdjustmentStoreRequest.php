<?php

declare(strict_types=1);

namespace App\Http\Requests\Employees;

use App\Models\Pas\PayrollAdjustment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a new one-off payroll adjustment (Week 7, Chunk 6).
 *
 * `kind` partitions additions vs deductions; `is_taxable` independently
 * controls whether the line folds into the BIR taxable base. `applies_on`
 * is the calendar date that decides which payroll period this adjustment
 * runs against (decision 1 in the Week 7 plan — see PayrollAdjustment::scopeForPeriod).
 *
 * `applied_pay_period_id` is server-controlled (cache column populated by
 * Week 9's batch backfill) — never accepted from user input.
 */
final class PayrollAdjustmentStoreRequest extends FormRequest
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
