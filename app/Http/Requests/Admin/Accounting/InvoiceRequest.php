<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Services\Accounting\InvoiceTotalsCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validation for drafting and editing an invoice or bill.
 *
 * Authorization is the controller's job via `Gate::authorize`, so
 * `authorize()` returns true here — the same contract the journal and
 * allowance forms use.
 *
 * The money figures are NOT validated as totals. Unlike a journal entry,
 * which the operator balances by hand and which must therefore be checked
 * before it is accepted, an invoice's totals are computed from its lines by
 * {@see InvoiceTotalsCalculator}. Validating a
 * client-supplied total would be validating a number the server is about to
 * overwrite, and worse, it would imply the client's arithmetic is
 * authoritative.
 */
final class InvoiceRequest extends FormRequest
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
        $tenantId = Tenant::current()?->getKey();
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where(
            fn ($query) => $tenantId === null ? $query : $query->where('school_id', $tenantId),
        );

        return [
            'type' => ['required', Rule::in(Invoice::TYPES)],
            // Scoped: a document must never be raised against another
            // tenant's contact.
            'contact_id' => ['required', 'integer', $scoped('pas_contacts')],
            'reference' => ['nullable', 'string', 'max:64'],
            'issue_date' => ['required', 'date'],
            // after_or_equal rather than after: terms of "due on receipt"
            // are ordinary, and a due date before the issue date is always
            // a typo.
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'is_vat_inclusive' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],

            // One line minimum — an invoice with nothing on it charges
            // nothing. The cap is a sanity bound against a runaway client,
            // not an accounting rule.
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            // Matches decimal(12,4). A negative quantity is allowed so a
            // discount can be expressed as a line rather than as a magic
            // negative price.
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

            $this->assertContactCanBeBilled($v);
        });
    }

    /**
     * A sales invoice needs a customer and a bill needs a supplier.
     *
     * Checked here rather than left to the database because the failure is
     * not a broken reference — the contact exists, it just is not the kind
     * of counterparty this document is for, and the operator picked the
     * wrong one from a list.
     */
    private function assertContactCanBeBilled(Validator $v): void
    {
        $contact = Contact::query()->find((int) $this->input('contact_id'));

        if ($contact === null) {
            return;
        }

        $isSales = $this->input('type') === Invoice::TYPE_SALES;

        if ($isSales && ! $contact->is_customer) {
            $v->errors()->add(
                'contact_id',
                "{$contact->name} is not marked as a customer, so a sales invoice cannot be raised against them.",
            );
        }

        if (! $isSales && ! $contact->is_supplier) {
            $v->errors()->add(
                'contact_id',
                "{$contact->name} is not marked as a supplier, so a bill cannot be recorded against them.",
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => 'An invoice needs at least one line.',
            'lines.*.quantity.not_in' => 'A line with a quantity of zero charges nothing. Remove it instead.',
            'lines.*.quantity.decimal' => 'Quantity can have at most 4 decimal places.',
            'lines.*.account_id.exists' => 'The selected account does not exist in this school.',
            'lines.*.tax_rate_id.exists' => 'The selected tax rate does not exist in this school.',
            'contact_id.exists' => 'The selected contact does not exist in this school.',
            'due_date.after_or_equal' => 'The due date cannot fall before the issue date.',
        ];
    }
}
