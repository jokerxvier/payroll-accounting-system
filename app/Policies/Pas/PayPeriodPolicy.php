<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\PayPeriod;
use App\Models\User;

/**
 * Authorization for the pay-periods admin surface.
 *
 * v1 scope (Phase 3 Week 9): super-admin only. Payroll-officer doesn't
 * own period creation — that's a system-configuration operation that
 * sits alongside contribution-table management.
 */
final class PayPeriodPolicy
{
    /** @var list<string> */
    private const SUPER_ADMIN_ROLES = ['super-admin'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function view(User $user, PayPeriod $period): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }
}
