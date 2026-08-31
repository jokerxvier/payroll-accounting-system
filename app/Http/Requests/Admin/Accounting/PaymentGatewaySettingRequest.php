<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Accounting;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\PaymentGatewaySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Entering or changing one gateway's credentials.
 *
 * The rule worth reading is on the secrets: **a blank secret means "leave it
 * alone", not "clear it"**. The screen never receives the stored value — it
 * would have to travel to the browser to be echoed back — so a form submitted
 * without retyping the key must not wipe it. Clearing is done by deactivating
 * the row, which is also the only action that stops it being used.
 *
 * The cash account rules deliberately mirror
 * {@see PaymentRequest::assertCashAccountIsAnAsset()}. Settled money lands
 * through the same posting path as a manually keyed receipt, so it has to
 * satisfy the same constraint — and stating it here rather than only in the
 * picker means a payload that skipped the UI does not get a weaker rule.
 */
final class PaymentGatewaySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PaymentGatewaySetting::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = Tenant::current()?->getKey();

        $scopedAccount = Rule::exists('pas_chart_of_accounts', 'id')
            ->where('school_id', $tenantId);

        return [
            'provider' => ['required', Rule::in(PaymentGatewaySetting::PROVIDERS)],
            'mode' => ['required', Rule::in(PaymentGatewaySetting::MODES)],
            'publishable_key' => ['nullable', 'string', 'max:255'],
            // Blank = unchanged. See the class docblock.
            'secret_key' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'cash_account_id' => ['nullable', 'integer', $scopedAccount],
            'fee_account_id' => ['nullable', 'integer', $scopedAccount],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->assertCashAccountIsCashEquivalent($v);
            $this->assertFeeAccountIsAnExpense($v);
            $this->assertActivationIsComplete($v);
        });
    }

    /**
     * Money settles into a cash account, same as any other receipt.
     */
    private function assertCashAccountIsCashEquivalent(Validator $v): void
    {
        $id = $this->input('cash_account_id');

        if ($id === null) {
            return;
        }

        $account = ChartOfAccount::query()->find((int) $id);

        if ($account === null) {
            return;
        }

        if ($account->type !== ChartOfAccount::TYPE_ASSET) {
            $v->errors()->add('cash_account_id', sprintf(
                '%s is a %s account. Settled money has to land in an asset account.',
                $account->name,
                $account->type,
            ));

            return;
        }

        if (! $account->is_cash_equivalent) {
            $v->errors()->add('cash_account_id', sprintf(
                '%s is not marked as a cash account. Mark it as one in the chart of accounts, or pick a bank account.',
                $account->name,
            ));
        }
    }

    /**
     * The gateway's cut is a cost of collecting, so it belongs in expenses.
     */
    private function assertFeeAccountIsAnExpense(Validator $v): void
    {
        $id = $this->input('fee_account_id');

        if ($id === null) {
            return;
        }

        $account = ChartOfAccount::query()->find((int) $id);

        if ($account !== null && $account->type !== ChartOfAccount::TYPE_EXPENSE) {
            $v->errors()->add('fee_account_id', sprintf(
                '%s is a %s account. A gateway fee is an expense.',
                $account->name,
                $account->type,
            ));
        }
    }

    /**
     * A row can be saved half-finished. It cannot be switched ON half-finished.
     *
     * Activating an incomplete row would put a Pay button in front of a
     * customer that fails the moment they press it — or worse, take their
     * money through a webhook we cannot verify.
     *
     * The two account fields are deliberately NOT checked here: they are
     * overrides, and leaving them blank is the normal answer. Whether an
     * account actually resolves is `PaymentGatewaySetting::isUsable()`'s
     * question, because the default can go missing long after this form was
     * last submitted.
     */
    private function assertActivationIsComplete(Validator $v): void
    {
        if (! $this->boolean('is_active')) {
            return;
        }

        // Looked up by (provider, mode) rather than a route parameter: the
        // store endpoint is a plain POST addressing that pair, not a bound
        // model. Reaching for a route binding here silently found nothing,
        // which made "blank means unchanged" collapse into "blank means
        // missing" and refused every edit that did not retype the key.
        $existing = PaymentGatewaySetting::query()
            ->where('provider', $this->input('provider'))
            ->where('mode', $this->input('mode'))
            ->first();

        $storedSecret = $existing?->secret_key;
        $storedWebhook = $existing?->webhook_secret;

        $secret = $this->filled('secret_key') ? $this->input('secret_key') : $storedSecret;
        $webhook = $this->filled('webhook_secret') ? $this->input('webhook_secret') : $storedWebhook;

        if ($secret === null || $secret === '') {
            $v->errors()->add('secret_key', 'A secret key is needed before this gateway can be switched on.');
        }

        if ($webhook === null || $webhook === '') {
            $v->errors()->add('webhook_secret', 'A webhook signing secret is needed before this gateway can be switched on. Without it a delivery cannot be verified.');
        }

    }
}
