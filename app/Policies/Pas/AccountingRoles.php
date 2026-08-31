<?php

declare(strict_types=1);

namespace App\Policies\Pas;

/**
 * The role lists shared by every Phase 5 accounting policy.
 *
 * Defined once rather than restated per policy: `CLAUDE.md` requires the
 * sidebar's visibility constants to *equal* the page's policy set, and three
 * policies each carrying their own copy of the same list is precisely how
 * that equality drifts. `app-sidebar.tsx` cites this class by name.
 *
 * Why `payroll-officer` is in the manage list, and why there is an
 * `accountant` role that nothing currently maps to:
 *
 *   `config/payroll.php` maps LMS role 6 ("Accountant") and role 13
 *   ("Accounts/Finance Officer") to `payroll-officer`, and
 *   `AssignPayrollRoleOnLogin` re-applies that mapping with `syncRoles` on
 *   *every* login — so a hand-assigned role is stripped at the user's next
 *   sign-in. The people who will actually use this module are therefore
 *   carrying `payroll-officer` today, and gating on `accountant` alone would
 *   ship a module nobody can open.
 *
 *   Remapping 6/13 to `accountant` is the obviously tidier end state, but
 *   `syncRoles` *replaces* rather than adds, so flipping the mapping would
 *   silently revoke those users' payroll access. That trade-off is Open
 *   Question 3 in the Phase 5 plan and belongs to the client, not to this
 *   slice. The `accountant` role is seeded and allowlisted here so the
 *   remap becomes a one-line config change when the answer arrives.
 */
final class AccountingRoles
{
    /**
     * Create, edit, and deactivate chart-of-accounts rows, tax rates, and
     * accounting periods.
     *
     * @var list<string>
     */
    public const MANAGE = ['super-admin', 'accountant', 'payroll-officer'];

    /**
     * Read the ledger setup. Adds `auditor`, whose whole function is
     * read-only inspection.
     *
     * @var list<string>
     */
    public const VIEW = ['super-admin', 'accountant', 'payroll-officer', 'auditor'];

    /**
     * Post an entry to the ledger, and reverse one that is already posted.
     *
     * Narrower than MANAGE on purpose, giving the journal the same
     * maker-checker shape as the payroll run lifecycle: anyone in MANAGE can
     * draft an entry and get the figures right, but committing it to the
     * books — or correcting something already committed — sits with the
     * people who own the ledger.
     *
     * @var list<string>
     */
    public const POST_LEDGER = ['super-admin', 'accountant'];

    /**
     * Enter and change payment gateway credentials.
     *
     * The narrowest list in this class, narrower even than CLOSE_PERIOD. A
     * closed period can be reopened; a leaked or swapped secret key can move
     * money to somewhere else entirely, and the person who did it is not
     * recorded in the value because `PaymentGatewaySetting::auditExclude()`
     * deliberately keeps credentials out of the audit trail. Kept to
     * `super-admin` — plus platform admins, who reach it through the
     * `Gate::before` short-circuit like everything else.
     *
     * @var list<string>
     */
    public const PAYMENT_GATEWAY = ['super-admin'];

    /**
     * Close and reopen an accounting period.
     *
     * Deliberately narrower than MANAGE: closing freezes the ledger and
     * reopening un-freezes it, which is the strongest control in the module.
     * Kept away from the broader payroll-ops population.
     *
     * @var list<string>
     */
    public const CLOSE_PERIOD = ['super-admin', 'accountant'];
}
