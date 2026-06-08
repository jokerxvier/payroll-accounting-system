<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Lms\User as LmsUser;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Maps the authenticated user's LMS role_id to a payroll Spatie role on login.
 *
 * Phase A.2 update: `App\Models\User` now lives on `pas_users`, which has no
 * `role_id` column. The role lookup follows the cross-reference column:
 *   pas_users.lms_user_id → lms.users.id → lms.users.role_id
 *
 * Behaviour:
 *  - If pas_users.lms_user_id is null, the row is a payroll-native user
 *    (e.g., platform-admin per the f4ee05d / e9fddb2 design, or a future
 *    invite-only ops path). These users manage their roles independently
 *    of the LMS mapping; the listener returns early and emits a debug log
 *    for trace purposes only — not a warning, because this is an expected
 *    and frequent path on every platform-admin login.
 *  - LMS read failures degrade gracefully (log + return early without
 *    touching roles). Same defensive pattern as the dashboard / employees
 *    index / payroll preview controllers.
 *  - If LMS role_id is NOT in payroll.employee_role_allowlist, no role is
 *    assigned (a Student or Parent must never receive a payroll role).
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

        $lmsUserId = $user->getAttribute('lms_user_id');

        if ($lmsUserId === null) {
            // Payroll-native user (platform-admin or future invite-only
            // ops). Expected on every such login — emit at debug so the
            // trace is available without flooding the WARNING channel.
            Log::debug('Login: pas_user has no lms_user_id; skipping LMS role mapping.', [
                'user_id' => $user->getKey(),
            ]);

            return;
        }

        try {
            $lmsRow = LmsUser::query()->whereKey($lmsUserId)->first();
            $rawRoleId = $lmsRow?->getAttribute('role_id');
        } catch (Throwable $e) {
            // LMS unreachable — leave existing roles untouched. Mirrors the
            // defensive pattern in EloquentEmployeeRepository / the dashboard.
            report($e);

            return;
        }

        $roleId = $rawRoleId === null ? null : (int) $rawRoleId;

        /** @var array<int, int> $allowlist */
        $allowlist = (array) config('payroll.employee_role_allowlist', []);

        if ($roleId === null || ! in_array($roleId, $allowlist, true)) {
            Log::warning('Login: LMS role_id outside payroll allowlist; no role assigned.', [
                'user_id' => $user->getKey(),
                'lms_user_id' => $lmsUserId,
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
