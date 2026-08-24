<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\ChartOfAccount;
use App\Models\User;

/**
 * Authorization for the chart-of-accounts admin surface.
 *
 * Role lists come from {@see AccountingRoles}. `platform-admin` is not listed
 * anywhere here on purpose — the `Gate::before` short-circuit in
 * `AppServiceProvider::registerPlatformAdminGate()` grants it every ability
 * already, and per `CLAUDE.md` policies must rely on that rather than
 * re-listing the role.
 *
 * System accounts (`is_locked`) are exempt from delete and from re-coding:
 * the software posts to them by `system_code`, so losing one breaks invoice,
 * bill, payment, or payroll posting. The lock is enforced here rather than in
 * the controller so every caller inherits it.
 */
final class ChartOfAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function view(User $user, ChartOfAccount $account): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    /**
     * Locked system accounts stay editable — an operator may legitimately
     * rename "Accounts Receivable" or re-file its cash-flow category. What
     * they may not do is change the `code` or `system_code`; that narrower
     * rule lives in the FormRequest, which can see the submitted values.
     */
    public function update(User $user, ChartOfAccount $account): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    public function delete(User $user, ChartOfAccount $account): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE)
            && ! $account->is_locked;
    }
}
