<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\PaymentGatewaySetting;
use App\Models\User;

/**
 * Who may see and change a school's gateway credentials.
 *
 * Every ability resolves to the same narrow list. There is no read-only tier
 * on purpose: the screen exists to enter secrets, and the only thing it shows
 * of an existing one is four masked characters — a viewer role would grant
 * sight of which gateway is live and in which mode, for no operational
 * benefit.
 */
final class PaymentGatewaySettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::PAYMENT_GATEWAY);
    }

    public function view(User $user, PaymentGatewaySetting $setting): bool
    {
        return $user->hasAnyRole(AccountingRoles::PAYMENT_GATEWAY);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::PAYMENT_GATEWAY);
    }

    public function update(User $user, PaymentGatewaySetting $setting): bool
    {
        return $user->hasAnyRole(AccountingRoles::PAYMENT_GATEWAY);
    }

    /**
     * Deleting is deliberately absent.
     *
     * A row nobody wants is deactivated, which stops it being used while
     * keeping the record that it once existed. Removing the row would erase
     * which gateway a historical payment was taken through.
     */
    public function delete(User $user, PaymentGatewaySetting $setting): bool
    {
        return false;
    }
}
