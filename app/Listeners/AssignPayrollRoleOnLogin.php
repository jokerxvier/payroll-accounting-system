<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/**
 * Maps the authenticated user's LMS role_id to a payroll Spatie role on login.
 *
 * Behaviour:
 *  - LMS role_id is read from the user row.
 *  - If role_id is NOT in payroll.employee_role_allowlist, no role is
 *    assigned (a Student or Parent must never receive a payroll role).
 *    A warning is logged so we can detect surprise role_ids in production.
 *  - If role_id IS allowlisted, look up payroll.lms_role_to_payroll_role
 *    and syncRoles([...]) the corresponding payroll role. If the role_id is
 *    allowlisted but unmapped, fall back to the 'employee' role.
 *
 * Idempotent: syncRoles replaces the role set on every login, so a one-time
 * promotion that the LMS later revokes will be undone on the next login.
 *
 * Run synchronously (not queued) — assignment is a single pivot write and
 * must complete before the request returns so the user has the right role
 * for their first authenticated request.
 */
final class AssignPayrollRoleOnLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $rawRoleId = $user->getAttribute('role_id');
        $roleId = $rawRoleId === null ? null : (int) $rawRoleId;

        /** @var array<int, int> $allowlist */
        $allowlist = (array) config('payroll.employee_role_allowlist', []);

        if ($roleId === null || ! in_array($roleId, $allowlist, true)) {
            Log::warning('Login: LMS role_id outside payroll allowlist; no role assigned.', [
                'user_id' => $user->getKey(),
                'lms_role_id' => $roleId,
            ]);

            return;
        }

        /** @var array<int, string> $map */
        $map = (array) config('payroll.lms_role_to_payroll_role', []);

        $payrollRole = $map[$roleId] ?? 'employee';

        // syncRoles replaces all existing role assignments; this keeps the
        // mapping authoritative on every login (idempotent).
        $user->syncRoles([$payrollRole]);
    }
}
