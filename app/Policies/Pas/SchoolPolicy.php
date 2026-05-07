<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\School;
use App\Models\User;

/**
 * Authorization for the schools / tenant-registry admin surface.
 *
 * Phase B.2 — super-admin exclusive. Tenant onboarding is a system-config
 * task; payroll-officer / hr / auditor / employee have no business in
 * `/admin/schools` even at read-time because LMS DB credentials live here.
 *
 * The before() hook short-circuits to true for super-admin and returns
 * null otherwise so the explicit method (always false) wins. Returning
 * `false` in before() would mask the explicit method's denial path,
 * which is fine here, but `null` is the canonical "fall through" pattern
 * and keeps the door open for adding role-specific carve-outs later
 * without revisiting every method.
 */
final class SchoolPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('super-admin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, School $school): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, School $school): bool
    {
        return false;
    }

    public function delete(User $user, School $school): bool
    {
        return false;
    }
}
