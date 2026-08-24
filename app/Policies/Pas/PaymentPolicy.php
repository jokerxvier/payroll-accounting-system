<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\Payment;
use App\Models\User;

/**
 * Authorization for payments.
 *
 * Maker-checker, the same shape as invoices and the journal:
 * {@see AccountingRoles::MANAGE} keys a payment and allocates it,
 * {@see AccountingRoles::POST_LEDGER} commits it — because posting is what
 * puts the money in the books and settles the invoices behind it.
 *
 * Every mutating ability also consults the payment's own state predicates, so
 * the policy answers "may this user do this to this row *as it stands*" and
 * the controller can hand the same booleans to the client as `can` flags.
 *
 * The state predicates are not a substitute for the controller's own guards.
 * `Gate::before` grants a platform admin every ability, short-circuiting this
 * class entirely, so a posted payment is protected by an `abort_if` outside
 * authorization as well.
 *
 * `platform-admin` is absent by design — the `Gate::before` short-circuit in
 * `AppServiceProvider::registerPlatformAdminGate()` grants it everything, and
 * per `CLAUDE.md` policies rely on that rather than re-listing the role.
 */
final class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->hasAnyRole(AccountingRoles::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE);
    }

    /**
     * Edit a draft. Refused once posted — the money has reached the ledger
     * and settled invoices, and re-keying it would silently move both.
     */
    public function update(User $user, Payment $payment): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE)
            && $payment->isMutable();
    }

    /**
     * Delete a draft outright.
     *
     * Legal only before posting, where nothing has reached the ledger and no
     * invoice balance depends on it. A posted payment is undone by voiding,
     * which leaves the correction visible.
     */
    public function delete(User $user, Payment $payment): bool
    {
        return $user->hasAnyRole(AccountingRoles::MANAGE)
            && $payment->isMutable();
    }

    public function post(User $user, Payment $payment): bool
    {
        return $user->hasAnyRole(AccountingRoles::POST_LEDGER)
            && $payment->isPostable();
    }

    public function void(User $user, Payment $payment): bool
    {
        return $user->hasAnyRole(AccountingRoles::POST_LEDGER)
            && $payment->isVoidable();
    }
}
