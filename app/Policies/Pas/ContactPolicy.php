<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\Contact;
use App\Models\User;

/**
 * Authorization for the contact register.
 *
 * Role lists come from {@see AccountingRoles}. There is no POST_LEDGER
 * ability here: a contact posts nothing and has no lifecycle — it is
 * reference data that documents point at.
 *
 * `platform-admin` is absent by design; the `Gate::before` short-circuit in
 * `AppServiceProvider::registerPlatformAdminGate()` grants it everything
 * already, and per `CLAUDE.md` policies rely on that rather than re-listing
 * the role.
 */
final class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    /**
     * Delete a contact.
     *
     * Permitted at the role level. Whether a specific contact CAN go is a
     * data question, not an authorization one — once Slice 5's documents
     * reference it, the controller refuses and points at deactivating
     * instead.
     */
    public function delete(User $user, Contact $contact): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }
}
