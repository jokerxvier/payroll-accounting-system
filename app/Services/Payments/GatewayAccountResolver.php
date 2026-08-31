<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\PaymentGatewaySetting;
use App\Services\Accounting\ControlAccountResolver;
use RuntimeException;

/**
 * Which accounts a gateway receipt posts through.
 *
 * The school's own choice wins; otherwise the default. Same shape as
 * {@see ControlAccountResolver}, and for the same reason — an operator pasting
 * API keys should not be asked two accounting questions whose answer is the
 * same for almost every school.
 *
 * **The two sides resolve differently, and that is deliberate.**
 *
 * The fee account comes from `MERCHANT_FEES`, a real system account: gateway
 * fees are posted by the software alone, which is exactly what a system code
 * means here.
 *
 * The cash account comes from a chart CODE in config instead. Giving a cash
 * account a `system_code` would silently remove it from the manual payment
 * picker — `PaymentController::cashAccountOptions()` excludes system accounts
 * on purpose, and says so — and breaking hand-keyed receipts to tidy up a
 * gateway form is a bad trade. Resolving by code follows the same
 * config-not-code precedent as the payroll account mapping.
 *
 * Both throw when nothing resolves. That matches
 * {@see ControlAccountResolver::systemAccount()}: quietly posting real money
 * into a substitute account would put it somewhere nobody chose.
 */
final class GatewayAccountResolver
{
    public function __construct(
        private readonly ControlAccountResolver $controlAccounts,
    ) {}

    /**
     * Where settled money lands.
     *
     * @throws RuntimeException When neither the override nor the default
     *                          resolves.
     */
    public function resolveCash(PaymentGatewaySetting $setting): ChartOfAccount
    {
        $override = $this->override($setting->cash_account_id);

        if ($override !== null) {
            return $override;
        }

        $code = (string) config('accounting.gateway.default_cash_account_code');

        $account = ChartOfAccount::query()->where('code', $code)->first();

        if ($account === null) {
            throw new RuntimeException(sprintf(
                "This school's chart of accounts has no account at code '%s' for settled money to land in. Choose one on the gateway settings screen.",
                $code,
            ));
        }

        return $account;
    }

    /**
     * Where the gateway's cut is expensed.
     *
     * @throws RuntimeException When neither the override nor the system
     *                          account resolves.
     */
    public function resolveFee(PaymentGatewaySetting $setting): ChartOfAccount
    {
        return $this->override($setting->fee_account_id)
            ?? $this->controlAccounts->systemAccount(ChartOfAccount::SYSTEM_MERCHANT_FEES);
    }

    /**
     * Whether both sides resolve, without caring which.
     *
     * Used to decide whether a gateway may be switched on. Asking here rather
     * than at posting time is what keeps the failure in front of an operator
     * instead of in front of a customer who has already paid.
     */
    public function bothResolve(PaymentGatewaySetting $setting): bool
    {
        try {
            $this->resolveCash($setting);
            $this->resolveFee($setting);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * An override, but only while it still points at something.
     *
     * A deleted account falls back to the default rather than throwing —
     * the same soft failure {@see ControlAccountResolver::resolve()} allows,
     * because a stale id is a configuration wrinkle, not a reason to refuse
     * money that has already arrived.
     */
    private function override(?int $accountId): ?ChartOfAccount
    {
        if ($accountId === null) {
            return null;
        }

        return ChartOfAccount::query()->find($accountId);
    }
}
