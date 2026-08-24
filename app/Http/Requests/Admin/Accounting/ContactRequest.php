<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validates both creation and editing of a `pas_contacts` row.
 *
 * One class for both, as {@see TaxRateRequest} does: the rules are identical,
 * and `ignore(null)` is already a no-op on create.
 *
 * Authorization happens in the controller via Gate::authorize(); this request
 * always returns true from authorize() so the policy stays the single source
 * of truth.
 */
final class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the TIN before the unique rule sees it.
     *
     * "123-456-789" and "123456789" are the same tax number, and storing both
     * would defeat the point of the unique index. Punctuation is stripped and
     * the digits kept.
     */
    protected function prepareForValidation(): void
    {
        $tin = $this->input('tin');

        if (is_string($tin)) {
            $digits = preg_replace('/\D+/', '', $tin) ?? '';

            $this->merge(['tin' => $digits === '' ? null : $digits]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = Tenant::current()?->getKey();
        $contact = $this->routeContact();

        $codeUnique = Rule::unique('pas_contacts', 'code')->ignore($contact?->getKey());
        $tinUnique = Rule::unique('pas_contacts', 'tin')->ignore($contact?->getKey());

        if ($tenantId !== null) {
            $codeUnique = $codeUnique->where('school_id', $tenantId);
            $tinUnique = $tinUnique->where('school_id', $tenantId);
        }

        return [
            'code' => ['required', 'string', 'max:32', $codeUnique],
            'name' => ['required', 'string', 'max:160'],
            'is_customer' => ['required', 'boolean'],
            'is_supplier' => ['required', 'boolean'],
            // Digits only by the time it reaches here. PH TINs are 9 digits,
            // or 12 with a branch code; the bounds are a sanity check rather
            // than a BIR rule, because which documents this school is
            // registered to issue is still an open question for Slice 5.
            'tin' => ['nullable', 'string', 'digits_between:9,12', $tinUnique],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'receivable_account_id' => [
                'nullable',
                'integer',
                // Scoped so a contact cannot be pointed at another tenant's
                // ledger account.
                Rule::exists('pas_chart_of_accounts', 'id')->where(
                    fn ($query) => $tenantId === null ? $query : $query->where('school_id', $tenantId)
                ),
            ],
            'payable_account_id' => [
                'nullable',
                'integer',
                Rule::exists('pas_chart_of_accounts', 'id')->where(
                    fn ($query) => $tenantId === null ? $query : $query->where('school_id', $tenantId)
                ),
            ],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->boolean('is_customer') && ! $this->boolean('is_supplier')) {
                // A contact that is neither cannot appear on any document.
                $v->errors()->add(
                    'is_customer',
                    'Mark this contact as a customer, a supplier, or both — one that is neither cannot be used on any document.'
                );
            }

            $this->assertAccountType(
                $v,
                'receivable_account_id',
                ChartOfAccount::TYPE_ASSET,
                'A receivable control account must be an asset — money owed to the school.',
            );

            $this->assertAccountType(
                $v,
                'payable_account_id',
                ChartOfAccount::TYPE_LIABILITY,
                'A payable control account must be a liability — money the school owes.',
            );
        });
    }

    /**
     * Guard the account overrides against the wrong side of the ledger.
     *
     * The pickers only offer the right type, but a hand-built request could
     * point a customer's receivable at an expense account, which would
     * silently corrupt every report that reads it.
     */
    private function assertAccountType(
        Validator $validator,
        string $field,
        string $expectedType,
        string $message,
    ): void {
        $id = $this->input($field);

        if ($id === null || $validator->errors()->has($field)) {
            return;
        }

        $type = ChartOfAccount::query()->whereKey((int) $id)->value('type');

        if ($type !== null && $type !== $expectedType) {
            $validator->errors()->add($field, $message);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tin.digits_between' => 'A TIN is 9 digits, or 12 with a branch code.',
            'tin.unique' => 'Another contact in this school already has that TIN.',
            'receivable_account_id.exists' => 'The selected account does not exist in this school.',
            'payable_account_id.exists' => 'The selected account does not exist in this school.',
        ];
    }

    private function routeContact(): ?Contact
    {
        $contact = $this->route('contact');

        return $contact instanceof Contact ? $contact : null;
    }
}
