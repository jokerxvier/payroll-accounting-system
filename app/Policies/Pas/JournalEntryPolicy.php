<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\JournalEntry;
use App\Models\User;

/**
 * Authorization for the journal.
 *
 * Maker-checker, mirroring the payroll run lifecycle: {@see AccountingRoles::MANAGE}
 * drafts entries, {@see AccountingRoles::POST_LEDGER} commits them and
 * corrects what is already committed.
 *
 * Every mutating ability also consults the entry's own state predicates, so
 * the policy answers "may this user do this to this row *as it stands*" and
 * the controller can hand the same booleans to the client as `can` flags —
 * the UI then never offers a transition the server would refuse.
 *
 * `platform-admin` is absent by design: the `Gate::before` short-circuit in
 * `AppServiceProvider::registerPlatformAdminGate()` grants it everything
 * already, and per `CLAUDE.md` policies rely on that rather than re-listing
 * the role.
 */
final class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    /**
     * Edit a draft. Refused once posted — a posted entry's figures are
     * history, and correcting them means posting a reversal.
     */
    public function update(User $user, JournalEntry $entry): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE)
            && $entry->isMutable();
    }

    /**
     * Delete a draft outright.
     *
     * Legal only before posting, where there is nothing in the ledger to
     * unwind and no history to preserve. This is the one place the project's
     * "void, don't delete" rule does not apply, because nothing has happened
     * yet.
     */
    public function delete(User $user, JournalEntry $entry): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE)
            && $entry->isMutable();
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        return $user->hasAnyRole(AccountingRoles::POST_LEDGER)
            && $entry->isPostable();
    }

    /**
     * Import and post the cutover snapshot.
     *
     * Instance-free because there is no entry to check yet — the whole point
     * of the flow is that it builds one. It sits with POST_LEDGER rather
     * than MANAGE for the same reason {@see self::post()} does, and with
     * more force: a snapshot writes an opening figure to every account at
     * once, and is corrected only by reversing it.
     */
    public function postOpeningBalance(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::POST_LEDGER);
    }

    /**
     * Post a reversing entry against a posted one.
     *
     * `isReversible()` refuses a second reversal — overshooting would leave
     * the account wrong by the full amount in the other direction.
     */
    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $user->hasAnyRole(AccountingRoles::POST_LEDGER)
            && $entry->isReversible();
    }
}
