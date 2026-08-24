<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\AccountingPeriod;
use App\Models\User;

/**
 * Authorization for accounting periods.
 *
 * Closing and reopening use the narrower {@see AccountingRoles::CLOSE_PERIOD}
 * list: closing freezes the ledger and reopening un-freezes it, which is the
 * strongest control in the module.
 *
 * The status guards below (`isOpen()` / `isClosed()`) mirror the pattern
 * `PayrollRunPolicy` uses — the policy answers "may this user do this to this
 * row *in its current state*", so the controller can hand the same booleans
 * to the client as `can` flags and the UI never offers an illegal transition.
 */
final class AccountingPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function view(User $user, AccountingPeriod $period): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    /**
     * Edit a period's dates or label. Legal only while it is open — a closed
     * period's boundaries are what every posted entry was filed against.
     */
    public function update(User $user, AccountingPeriod $period): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE)
            && $period->isOpen();
    }

    public function close(User $user, AccountingPeriod $period): bool
    {
        return $user->hasAnyRole(AccountingRoles::CLOSE_PERIOD)
            && $period->isOpen();
    }

    public function reopen(User $user, AccountingPeriod $period): bool
    {
        return $user->hasAnyRole(AccountingRoles::CLOSE_PERIOD)
            && $period->isClosed();
    }

    /**
     * Periods are never deleted. Slice 2 attaches journal entries to them, so
     * a deleted period would orphan the ledger's own filing system; a period
     * created in error is edited or left unused instead.
     */
    public function delete(User $user, AccountingPeriod $period): bool
    {
        return false;
    }
}
