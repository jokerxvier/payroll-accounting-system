<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use RuntimeException;

/**
 * Which receivable or payable account a document posts against.
 *
 * A contact's own override wins, so a school tracking a major supplier or a
 * scholarship fund on its own control account gets that. Otherwise the
 * school's `AR_CONTROL` / `AP_CONTROL` — the fallback the contact register
 * was built around.
 *
 * Extracted from InvoicePostingService when payments became a second caller:
 * an invoice debiting a receivable and a receipt crediting the same one have
 * to agree on which account that is, and two copies of the resolution would
 * eventually disagree.
 */
final class ControlAccountResolver
{
    /**
     * @param  bool  $receivable  True for the AR side, false for AP.
     *
     * @throws RuntimeException The school has no such system account.
     */
    public function resolve(?Contact $contact, bool $receivable): ChartOfAccount
    {
        $overrideId = $receivable
            ? $contact?->receivable_account_id
            : $contact?->payable_account_id;

        if ($overrideId !== null) {
            $override = ChartOfAccount::query()->find($overrideId);

            if ($override !== null) {
                return $override;
            }
        }

        return $this->systemAccount(
            $receivable
                ? ChartOfAccount::SYSTEM_AR_CONTROL
                : ChartOfAccount::SYSTEM_AP_CONTROL,
        );
    }

    /**
     * Resolve any system account within the posting school.
     *
     * Throws rather than inventing one. A missing control account is a setup
     * error the operator has to fix, and quietly posting real money into a
     * substitute would put it somewhere nobody chose.
     *
     * @throws RuntimeException
     */
    public function systemAccount(string $systemCode): ChartOfAccount
    {
        $account = ChartOfAccount::query()->where('system_code', $systemCode)->first();

        if ($account === null) {
            throw new RuntimeException(sprintf(
                "This school's chart of accounts has no '%s' account, which posting needs in order to proceed.",
                $systemCode,
            ));
        }

        return $account;
    }
}
