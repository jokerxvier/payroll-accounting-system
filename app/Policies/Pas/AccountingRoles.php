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
