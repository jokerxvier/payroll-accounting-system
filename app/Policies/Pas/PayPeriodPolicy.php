<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\PayPeriod;
use App\Models\User;

/**
 * Authorization for the pay-periods admin surface.
 *
 * Read-only for payroll-officer + hr (they need to know the period schedule
 * to plan runs). Period creation stays super-admin — that's a system-
 * configuration operation that sits alongside contribution-table management.
 */
final class PayPeriodPolicy
{
    /** @var list<string> */
    private const SUPER_ADMIN_ROLES = ['super-admin'];

    /** @var list<string> */
    private const READ_ROLES = ['super-admin', 'payroll-officer', 'hr'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::READ_ROLES);
    }

    public function view(User $user, PayPeriod $period): bool
    {
        return $user->hasAnyRole(self::READ_ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }

    /**
     * Manually override a period's status. Same super-admin gate as create —
     * changing whether a period is open/closed is a configuration action.
     */
    public function update(User $user, PayPeriod $period): bool
    {
        return $user->hasAnyRole(self::SUPER_ADMIN_ROLES);
    }
}
