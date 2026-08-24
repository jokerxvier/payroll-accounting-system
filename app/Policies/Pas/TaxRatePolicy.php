<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\TaxRate;
use App\Models\User;

/**
 * Authorization for the tax-rate catalog.
 *
 * Role lists come from {@see AccountingRoles}. `platform-admin` is granted by
 * the `Gate::before` short-circuit, not by being listed here.
 */
final class TaxRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function view(User $user, TaxRate $taxRate): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    public function update(User $user, TaxRate $taxRate): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    public function delete(User $user, TaxRate $taxRate): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }
}
