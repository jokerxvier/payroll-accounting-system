<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\EmployeeProfile;
use App\Models\User;

class EmployeeProfilePolicy
{
    /**
     * Read-side allowlist: super-admin, payroll-officer, hr (write-capable
     * roles) plus auditor (read-only). The `employee` role is intentionally
     * excluded — there is no self-service view in v1.
     *
     * @var list<string>
     */
    private const READ_ROLES = ['super-admin', 'payroll-officer', 'hr', 'auditor'];

    /**
     * Write-side allowlist: only roles that may mutate payroll profile data.
     * Auditor is excluded from writes by design.
     *
     * @var list<string>
     */
    private const WRITE_ROLES = ['super-admin', 'payroll-officer', 'hr'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::READ_ROLES);
    }

    public function view(User $user, EmployeeProfile $profile): bool
    {
        return $user->hasAnyRole(self::READ_ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::WRITE_ROLES);
    }

    public function update(User $user, EmployeeProfile $profile): bool
    {
        return $user->hasAnyRole(self::WRITE_ROLES);
    }
}
