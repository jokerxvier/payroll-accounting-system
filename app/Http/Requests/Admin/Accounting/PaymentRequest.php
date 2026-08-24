<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Actions\Accounting\ApplyPaymentAllocations;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validation for keying and editing a payment.
 *
 * Authorization is the controller's job via `Gate::authorize`, so
 * `authorize()` returns true here.
 *
 * The allocation *rules* — that an invoice cannot be over-paid, that a
 * receipt cannot settle a bill, that the contact must match — are NOT
 * duplicated here. They live in
 * {@see ApplyPaymentAllocations}, which is the only
 * thing that can check them against a live remaining balance inside the
 * writing transaction. Restating them here would produce a second, weaker
 * copy that goes stale the moment a concurrent payment lands.
 *
 * What this request does check is shape: the right columns, the right types,
 * and that every id belongs to this school.
 */
final class PaymentRequest extends FormRequest
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
            'type' => ['required', Rule::in(Payment::TYPES)],
            'contact_id' => ['required', 'integer', $scoped('pas_contacts')],
            'payment_date' => ['required', 'date'],
            'amount_centavos' => ['required', 'integer', 'min:1'],
            'cash_account_id' => ['required', 'integer', $scoped('pas_chart_of_accounts')],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Allocations are optional: a payment received ahead of any
            // invoice is an advance, not an error.
            'allocations' => ['present', 'array', 'max:200'],
            'allocations.*.invoice_id' => ['required', 'integer', $scoped('pas_invoices')],
            'allocations.*.amount_centavos' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->assertContactMatchesType($v);
            $this->assertCashAccountIsAnAsset($v);
        });
    }

    /**
     * A receipt comes from a customer and a disbursement goes to a supplier.
     *
     * Checked here rather than left to the database because the contact
     * exists — it is simply not the kind of counterparty this payment is for,
     * and the operator picked the wrong one from a list.
     */
    private function assertContactMatchesType(Validator $v): void
    {
        $contact = Contact::query()->find((int) $this->input('contact_id'));

        if ($contact === null) {
            return;
        }

        $isReceipt = $this->input('type') === Payment::TYPE_RECEIPT;

        if ($isReceipt && ! $contact->is_customer) {
            $v->errors()->add(
                'contact_id',
                "{$contact->name} is not marked as a customer, so a receipt cannot be recorded against them.",
            );
        }

        if (! $isReceipt && ! $contact->is_supplier) {
            $v->errors()->add(
                'contact_id',
                "{$contact->name} is not marked as a supplier, so a disbursement cannot be recorded against them.",
            );
        }
    }

    /**
     * Money has to move through an asset account.
     *
     * Picking an income or liability account here would balance arithmetically
     * and describe something that never happened — the whole point of the
     * cash line is to say which real account the money is sitting in.
     */
    private function assertCashAccountIsAnAsset(Validator $v): void
    {
        $account = ChartOfAccount::query()->find((int) $this->input('cash_account_id'));

        if ($account === null) {
            return;
        }

        if ($account->type !== ChartOfAccount::TYPE_ASSET) {
            $v->errors()->add(
                'cash_account_id',
                "{$account->name} is a {$account->type} account. Money has to be received into, or paid out of, an asset account.",
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_centavos.min' => 'A payment has to move some money.',
            'contact_id.exists' => 'The selected contact does not exist in this school.',
            'cash_account_id.exists' => 'The selected account does not exist in this school.',
            'allocations.*.invoice_id.exists' => 'One of the selected documents does not exist in this school.',
            'allocations.*.amount_centavos.min' => 'Remove the line instead of allocating nothing to it.',
        ];
    }
}
