<?php

declare(strict_types=1);

namespace App\Policies\Pas;

use App\Models\Pas\School;
use App\Models\User;

/**
 * Authorization for the schools / tenant-registry admin surface.
 *
 * Phase B.2 — platform-admin exclusive. Tenant onboarding is a system-config
 * task; LMS-derived users (super-admin / payroll-officer / hr / auditor /
 * employee) have no business in `/admin/schools` even at read-time because
 * LMS DB credentials for OTHER schools live here. School-scoped super-admin
 * stays meaningful at school level (approve payroll runs, etc.) but loses
 * cross-tenant powers — those move exclusively to the platform-admin role.
 *
 * The before() hook short-circuits to true for platform-admin and returns
 * null otherwise so the explicit method (always false) wins. Returning
 * `false` in before() would mask the explicit method's denial path,
 * which is fine here, but `null` is the canonical "fall through" pattern
 * and keeps the door open for adding role-specific carve-outs later
 * without revisiting every method.
 *
 * Defense in depth: the `lms_user_id IS NULL` check ensures only payroll-
 * native users can reach this short-circuit. An LMS-derived user that
 * somehow gets the platform-admin role assigned (operator error or seed
 * mismatch) is still denied. Mirrors the gate in ApplyTenantOverride and
 * HandleInertiaRequests so all three layers agree on "what is a platform
 * admin".
 */
final class SchoolPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->getAttribute('lms_user_id') === null
            && $user->hasRole('platform-admin')
                ? true
                : null;
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
