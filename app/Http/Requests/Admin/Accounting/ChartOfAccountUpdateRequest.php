<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\ChartOfAccount;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validates an edit to an existing `pas_chart_of_accounts` row.
 *
 * Differs from the store request in three ways:
 *
 *  1. The unique rule on `code` ignores the row being edited.
 *  2. `parent_id` may not point at the account itself — a self-parent would
 *     make the hierarchy walk in the Balance Sheet non-terminating.
 *  3. Locked (system) accounts may be renamed and re-filed but may NOT have
 *     their `code` changed. Posting code resolves those accounts by
 *     `system_code`, and reports key off `code`; re-coding one mid-life
 *     leaves already-posted journal lines describing an account that no
 *     longer answers to that code.
 *
 * `type` is also frozen on locked accounts: turning the AR control account
 * into an expense would invert its normal balance under every posted entry.
 */
final class ChartOfAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $type = (string) $this->input('type');

        if (in_array($type, ChartOfAccount::TYPES, true)) {
            $this->merge([
                'normal_balance' => ChartOfAccount::normalBalanceForType($type),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = Tenant::current()?->getKey();
        $account = $this->routeAccount();

        $codeUniqueRule = Rule::unique('pas_chart_of_accounts', 'code')
            ->ignore($account?->getKey());
        if ($tenantId !== null) {
            $codeUniqueRule = $codeUniqueRule->where('school_id', $tenantId);
        }

        return [
            'code' => ['required', 'string', 'max:20', $codeUniqueRule],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', Rule::in(ChartOfAccount::TYPES)],
            'subtype' => ['nullable', 'string', 'max:40'],
            'normal_balance' => ['required', 'string', Rule::in(ChartOfAccount::NORMAL_BALANCES)],
            'cash_flow_category' => ['required', 'string', Rule::in(ChartOfAccount::CASH_FLOW_CATEGORIES)],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::notIn(array_filter([$account?->getKey()])),
                Rule::exists('pas_chart_of_accounts', 'id')->where(
                    fn ($query) => $tenantId === null ? $query : $query->where('school_id', $tenantId)
                ),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $account = $this->routeAccount();

            if ($account === null || ! $account->is_locked) {
                return;
            }

            if ((string) $this->input('code') !== $account->code) {
                $v->errors()->add(
                    'code',
                    'This is a system account. Its code is referenced by posted journal entries and cannot be changed.'
                );
            }

            if ((string) $this->input('type') !== $account->type) {
                $v->errors()->add(
                    'type',
                    'This is a system account. Changing its type would invert the normal balance of entries already posted to it.'
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
            'parent_id.not_in' => 'An account cannot be its own parent.',
            'parent_id.exists' => 'The selected parent account does not exist in this school.',
        ];
    }

    /**
     * The account being edited, resolved from route-model binding.
     */
    private function routeAccount(): ?ChartOfAccount
    {
        $account = $this->route('chartOfAccount');

        return $account instanceof ChartOfAccount ? $account : null;
    }
}
