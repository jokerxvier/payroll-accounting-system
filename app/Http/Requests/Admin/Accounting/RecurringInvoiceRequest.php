<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\RecurringInvoice;
use App\Services\Accounting\InvoiceBillingRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validation for a recurring schedule.
 *
 * Deliberately the same shape as {@see InvoiceRequest} — a schedule is an
 * invoice that has not happened yet, and the two must agree about what a
 * billable document looks like. The scoped `exists` rules matter more here
 * than there: an invoice is checked once by the person making it, while a
 * schedule is acted on unattended every month for a year.
 *
 * The generator re-checks the same rules on every run, because a schedule
 * validated in August can be wrong by December — guardians change, students
 * transfer, accounts are archived. This class stops a bad schedule being
 * *written*; `GenerateDueInvoices` stops a stale one being *acted on*.
 *
 * Authorization is the controller's job, as everywhere else in this module.
 */
final class RecurringInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = Tenant::current()?->getKey();

        // Scoped so a schedule can never be saved pointing at another school's
        // chart row, tax rate or contact.
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where(
            fn ($query) => $tenantId === null ? $query : $query->where('school_id', $tenantId),
        );

        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Invoice::TYPES)],
            'contact_id' => ['required', 'integer', $scoped('pas_contacts')],
            'lms_student_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:64'],
            'is_vat_inclusive' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],

            'frequency' => ['required', Rule::in(RecurringInvoice::FREQUENCIES)],
            'day_of_month' => ['required', 'integer', 'min:1', 'max:31'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['required', 'boolean'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'not_in:0'],
            'lines.*.unit_price_centavos' => ['required', 'integer'],
            'lines.*.account_id' => ['required', 'integer', $scoped('pas_chart_of_accounts')],
            'lines.*.tax_rate_id' => ['nullable', 'integer', $scoped('pas_tax_rates')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $rules = app(InvoiceBillingRules::class);
            $contactId = (int) $this->input('contact_id');
            $studentId = $this->input('lms_student_id');

            $reason = $rules->contactCannotBeBilled(
                Contact::query()->find($contactId),
                (string) $this->input('type'),
            ) ?? $rules->payerIsNotLinkedToStudent(
                $studentId === null ? null : (int) $studentId,
                $contactId,
            );

            if ($reason !== null) {
                $v->errors()->add('contact_id', $reason);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'A schedule needs at least one line, or it has nothing to charge.',
            'lines.*.quantity.not_in' => 'A quantity of zero would bill nothing.',
            'day_of_month.max' => 'Pick a day between 1 and 31. Short months are clamped to their last day.',
        ];
    }
}
