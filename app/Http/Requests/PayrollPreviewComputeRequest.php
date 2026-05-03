<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\ValueObjects\PayPeriodInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validation gate for the payroll preview compute endpoint.
 *
 * `lms_staff_id` is validated against `lms.sm_staffs` directly (the LMS-owned
 * identity table on the read-only `lms` connection). The downstream controller
 * resolves an EmployeeProfile via `lms_staff_id` — when no profile exists yet
 * the response carries `noProfile: true` so the UI can render an empty state
 * instead of a 422.
 *
 * `frequency` accepts the three preview-facing variants (Stage A Decision 4):
 *
 *   - monthly                 — full calendar month, e.g. May 2026
 *   - semi_monthly_first      — day 1..15 of the chosen month
 *   - semi_monthly_second     — day 16..end-of-month
 *
 * The controller maps each to the canonical {@see PayPeriodInput} factory.
 *
 * `year` is bounded [2020, 2050] — inside the seeded statutory effective-date
 * range. Earlier years would predate the SSS / PhilHealth / Pag-IBIG tables;
 * later years are out of horizon for this app's lifetime.
 *
 * @phpstan-import-type PayrollPreviewPayload from PayPeriodInput
 */
class PayrollPreviewComputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('payroll.preview');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lms_staff_id' => ['required', 'integer', 'exists:lms.sm_staffs,id'],
            'frequency' => ['required', Rule::in([
                'monthly',
                'semi_monthly_first',
                'semi_monthly_second',
            ])],
            'year' => ['required', 'integer', 'min:2020', 'max:2050'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ];
    }
}
