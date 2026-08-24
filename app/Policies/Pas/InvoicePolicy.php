<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\Invoice;
use App\Models\User;

/**
 * Authorization for invoices and bills.
 *
 * Maker-checker, the same shape as the journal: {@see AccountingRoles::MANAGE}
 * drafts a document, {@see AccountingRoles::POST_LEDGER} approves it — because
 * approving is what numbers it and puts it in the books.
 *
 * Every mutating ability also consults the invoice's own state predicates, so
 * the policy answers "may this user do this to this row *as it stands*" and
 * the controller can hand the same booleans to the client as `can` flags. The
 * UI then never offers a transition the server would refuse.
 *
 * The state predicates are not a substitute for the controller's own guards.
 * `Gate::before` grants a platform admin every ability, short-circuiting this
 * class entirely, so an issued document is protected by an `abort_if` outside
 * authorization as well — the gap proved real earlier in this phase, when a
 * platform admin could delete posted ledger history.
 *
 * `platform-admin` is absent by design: the `Gate::before` short-circuit in
 * `AppServiceProvider::registerPlatformAdminGate()` grants it everything
 * already, and per `CLAUDE.md` policies rely on that rather than re-listing
 * the role.
 */
final class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    /**
     * Edit a draft. Refused once approved — the document carries a
     * BIR-controlled serial and has hit the ledger, so its figures are what
     * the counterparty was told they owe.
     */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE)
            && $invoice->isMutable();
    }

    /**
     * Delete a draft outright.
     *
     * Legal only before approval, where no serial has been issued and
     * nothing has reached the ledger. Once issued, the document is cancelled
     * by voiding, which keeps the number accounted for.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE)
            && $invoice->isMutable();
    }

    /**
     * Approve: allocate the serial and post to the ledger.
     *
     * Sits with POST_LEDGER rather than MANAGE for the same reason posting a
     * journal entry does — anyone in MANAGE can draft a document and get the
     * figures right, but committing it to the books belongs to the people
     * who own them.
     */
    public function approve(User $user, Invoice $invoice): bool
    {
        return $user->hasAnyRole(AccountingRoles::POST_LEDGER)
            && $invoice->isApprovable();
    }

    /**
     * Cancel an issued document.
     *
     * POST_LEDGER, because voiding reverses a posted entry.
     */
    public function void(User $user, Invoice $invoice): bool
    {
        return $user->hasAnyRole(AccountingRoles::POST_LEDGER)
            && $invoice->isVoidable();
    }

    /**
     * Print the document face.
     *
     * VIEW rather than MANAGE: an auditor asked to check what a customer was
     * sent needs to see the actual document, not a reconstruction of it.
     */
    public function print(User $user, Invoice $invoice): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }
}
