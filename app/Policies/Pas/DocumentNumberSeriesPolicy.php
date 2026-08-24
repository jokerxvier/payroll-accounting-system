<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\DocumentNumberSeries;
use App\Models\User;

/**
 * Authorization for document numbering series.
 *
 * Narrower than the rest of the module: editing a series means editing the
 * Authority To Print details and the serial range the BIR issued, and moving
 * the counter would break the gapless guarantee the allocator exists to
 * provide. That sits with {@see AccountingRoles::POST_LEDGER}, the same
 * people who own the ledger, rather than with everyone who can draft a
 * document.
 *
 * There is no `delete`. A series that has issued numbers is the record of
 * which serials went out; removing it would orphan every document drawn from
 * it. Deactivate it instead — {@see DocumentNumberSeries} has `is_active` for
 * exactly that, and the allocator refuses an inactive series.
 */
final class DocumentNumberSeriesPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function view(User $user, DocumentNumberSeries $series): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::POST_LEDGER);
    }

    public function update(User $user, DocumentNumberSeries $series): bool
    {
        return $user->hasAnyRole(AccountingRoles::POST_LEDGER);
    }
}
