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
 * payroll task. There is no per-instance `view()` carve-out (the index page
 * shows everything) and no `delete()` (rows are immutable post-supersession;
 * adding a new effective_from row is the only way to change behavior).
 */
final class StatutoryContributionPolicy
{
    /** @var list<string> */
    private const SUPER_ADMIN_ROLES = ['super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function update(User $user, StatutoryContribution $row): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }
}
