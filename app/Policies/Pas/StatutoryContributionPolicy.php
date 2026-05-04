<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\StatutoryContribution;
use App\Models\User;

/**
 * Authorization for the contribution-tables admin surface.
 *
 * v1 scope: only super-admin manages the rate tables. Payroll-officer, hr,
 * auditor, and employee are explicitly out of scope — adjusting statutory
 * percentages and brackets is a system-configuration operation, not a daily
 * payroll task.
 *
 * Mutating actions (`update`, `void`) additionally require the row to be
 * editable: strictly future-dated, open-ended, and not already voided. Once
 * `effective_from` passes, the row may have driven a payroll computation
 * (Phase 3+ payslips) — mutating it would silently rewrite history. The same
 * lockout applies to superseded rows (`effective_to` set) since a later
 * version owns the live behavior. See {@see StatutoryContribution::isEditable()}.
 *
 * No `delete()` method: hard deletion is never permitted on this table —
 * voiding (via the `void` ability) is the only sanctioned removal path.
 */
final class StatutoryContributionPolicy
{
    /** @var list<string> */
    private const SUPER_ADMIN_ROLES = ['super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function view(User $user, StatutoryContribution $row): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function update(User $user, StatutoryContribution $row): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES) && $row->isEditable();
    }

    public function void(User $user, StatutoryContribution $row): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES) && $row->isEditable();
    }
}
