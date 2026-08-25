<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Pas\ChartOfAccount;
use Illuminate\Contracts\Validation\Validator;

/**
 * The one rule about which accounts may be marked as cash.
 *
 * Shared by the chart-of-accounts store and update requests rather than
 * restated in each: two copies of the same message are how the two drift, and
 * an operator who can mark a liability as cash breaks both the payment form's
 * account picker and the cash balance the Cash Flow Statement reconciles to.
 *
 * Assets only. A bank overdraft is arguably a cash equivalent under PAS 7
 * (presented as negative cash rather than as debt), but it is a liability
 * account, and admitting liabilities here to serve that one case would open
 * the door to every payable. A school that runs an overdraft can raise it;
 * until then the narrow rule is the correct one.
 */
trait ValidatesCashEquivalentAccounts
{
    /**
     * Reject `is_cash_equivalent` on anything that is not an asset account.
     *
     * Reads `type` from the request rather than the persisted row so an edit
     * that flips an asset to a liability and leaves the flag set is caught in
     * the same pass.
     */
    protected function validateCashEquivalentIsAnAsset(Validator $validator): void
    {
        if (! $this->boolean('is_cash_equivalent')) {
            return;
        }

        $type = (string) $this->input('type');

        if ($type === ChartOfAccount::TYPE_ASSET) {
            return;
        }

        $validator->errors()->add(
            'is_cash_equivalent',
            'Only an asset account can hold cash. Clear this, or change the account type to asset.',
        );
    }
}
