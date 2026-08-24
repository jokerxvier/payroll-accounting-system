<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\ChartOfAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Validates a new row for `pas_chart_of_accounts`.
 *
 * Authorization happens in the controller via Gate::authorize('create', ...);
 * this request always returns true from authorize() so the policy stays the
 * single source of truth — the same split every other admin request uses.
 *
 * `normal_balance` is deliberately NOT accepted from the client. It is
 * derived from `type` in prepareForValidation() via
 * {@see ChartOfAccount::normalBalanceForType()}. Letting an operator pick a
 * credit-normal asset account would silently corrupt every report that reads
 * the column, and there is no legitimate case for the two disagreeing.
 *
 * `system_code` is likewise not accepted. System accounts are created by the
 * seeder, not through the UI — minting a second AR control account would give
 * posting code two candidates and no way to choose.
 */
final class ChartOfAccountStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $type = (string) $this->input('type');

        // Only derive when the type is one we recognise; an unknown type is
        // reported by the `in:` rule below rather than by an exception here.
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
        // Tenant-scoped uniqueness — `code` is unique per school, so two
        // schools may each hold a "1100 Cash on Hand". Falling back to no
        // school filter when no tenant is current keeps console / bootstrap
        // paths working; in a real HTTP request NeedsTenant has always
        // resolved one.
        $tenantId = Tenant::current()?->getKey();

        $codeUniqueRule = Rule::unique('pas_chart_of_accounts', 'code');
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
                // Scoped existence check: a parent must belong to the same
                // school. Without the school_id clause an operator could
                // nest their account under another tenant's row.
                Rule::exists('pas_chart_of_accounts', 'id')->where(
                    fn ($query) => $tenantId === null ? $query : $query->where('school_id', $tenantId)
                ),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => 'The selected parent account does not exist in this school.',
        ];
    }
}
