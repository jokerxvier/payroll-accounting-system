<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\TaxRate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validates both creation and editing of a `pas_tax_rates` row.
 *
 * One class rather than the Store/Update pair used elsewhere in this
 * directory: the rules are identical for both operations. The only
 * difference a separate update request would carry is `ignore()` on the
 * unique rule, and `ignore(null)` is already a no-op on create. Two copies
 * of an identical rule set is a drift risk with nothing bought for it.
 *
 * Authorization happens in the controller via Gate::authorize(); this
 * request always returns true from authorize() so the policy stays the
 * single source of truth.
 *
 * `rate_bps` is submitted and stored in **basis points**, not percent:
 * 12% is 1200. The 10,000 ceiling (= 100%) is a sanity bound, not a tax
 * rule — it catches an operator typing `12` into what they assume is a
 * percent field, or slipping a digit into `120000`.
 *
 * Cross-field invariants enforced in withValidator():
 *   - A rate that actually posts tax (vat_sales / vat_purchase at a
 *     non-zero rate) must name the account it posts to. Without one, an
 *     invoice using the rate would compute VAT it has nowhere to book and
 *     the resulting journal entry would not balance.
 *   - Exempt and zero-rated rates must carry a zero rate. They exist to
 *     drive their own invoice subtotals, not to charge tax.
 */
final class TaxRateRequest extends FormRequest
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
        // Tenant-scoped uniqueness — two schools may each hold a
        // "VAT_12_SALES". Falling back to no school filter when no tenant is
        // current keeps console / bootstrap paths working; in a real HTTP
        // request NeedsTenant has always resolved one.
        $tenantId = Tenant::current()?->getKey();
        $taxRate = $this->routeTaxRate();

        $codeUniqueRule = Rule::unique('pas_tax_rates', 'code')
            ->ignore($taxRate?->getKey());
        if ($tenantId !== null) {
            $codeUniqueRule = $codeUniqueRule->where('school_id', $tenantId);
        }

        return [
            'code' => ['required', 'string', 'max:32', $codeUniqueRule],
            'name' => ['required', 'string', 'max:120'],
            'rate_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'type' => ['required', 'string', Rule::in(TaxRate::TYPES)],
            'account_id' => [
                'nullable',
                'integer',
                // Scoped so a rate cannot be pointed at another tenant's
                // ledger account.
                Rule::exists('pas_chart_of_accounts', 'id')->where(
                    fn ($query) => $tenantId === null ? $query : $query->where('school_id', $tenantId)
                ),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $type = (string) $this->input('type');
            $rateBps = (int) $this->input('rate_bps');

            $postsTax = $rateBps > 0 && in_array(
                $type,
                [TaxRate::TYPE_VAT_SALES, TaxRate::TYPE_VAT_PURCHASE],
                true,
            );

            if ($postsTax && $this->input('account_id') === null) {
                $v->errors()->add(
                    'account_id',
                    'A VAT rate must name the account it posts to, or an invoice using it cannot balance.'
                );
            }

            $isZeroByDefinition = in_array(
                $type,
                [TaxRate::TYPE_EXEMPT, TaxRate::TYPE_ZERO_RATED],
                true,
            );

            if ($isZeroByDefinition && $rateBps !== 0) {
                $v->errors()->add(
                    'rate_bps',
                    'Exempt and zero-rated tax rates must have a rate of 0.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.exists' => 'The selected account does not exist in this school.',
            'rate_bps.max' => 'The tax rate cannot exceed 10000 basis points (100%).',
        ];
    }

    /**
     * The tax rate being edited, when this is an update. Null on create.
     */
    private function routeTaxRate(): ?TaxRate
    {
        $taxRate = $this->route('taxRate');

        return $taxRate instanceof TaxRate ? $taxRate : null;
    }
}
