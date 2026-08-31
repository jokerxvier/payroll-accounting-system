<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\RecurringInvoice;
use App\Models\User;

/**
 * Authorization for recurring invoice schedules.
 *
 * Mirrors {@see InvoicePolicy}'s drafting half exactly: whoever may draft an
 * invoice may write the standing instruction that drafts one every month.
 * There is no `POST_LEDGER` ability here because a schedule never posts —
 * it produces drafts, and approving those is still the checker's job.
 *
 * That is the whole safety argument for letting an unattended job write at
 * all, so it is worth saying plainly: a maker who can set up a schedule has
 * not thereby gained the power to put anything in the books.
 *
 * `platform-admin` is absent by design — the `Gate::before` short-circuit in
 * `AppServiceProvider::registerPlatformAdminGate()` grants it everything.
 */
final class RecurringInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function view(User $user, RecurringInvoice $schedule): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    public function update(User $user, RecurringInvoice $schedule): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    /**
     * Deleting a schedule releases every period it has claimed, because the
     * claims cascade with it. That is the intended way to start a schedule
     * over — and the reason deleting is a maker's decision rather than
     * something the list offers casually.
     */
    public function delete(User $user, RecurringInvoice $schedule): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    /** Pausing and resuming is the same authority as editing. */
    public function pause(User $user, RecurringInvoice $schedule): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }
}
