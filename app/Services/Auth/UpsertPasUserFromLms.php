<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Lms\User as LmsUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Upserts a row in `pas_users` from the canonical LMS user record.
 *
 * Phase D.3 dropped id-preservation. The composite unique
 * `(school_id, lms_user_id)` is the authoritative key for an LMS user's
 * payroll mirror. New rows take an auto-increment id; the 5 FKs from
 * `pas_*` into `pas_users(id)` continue to work because `Auth::id()`
 * returns `pas_users.id` at write time, never the LMS `users.id`.
 *
 * Mirrors the LMS password hash so Fortify's session continuity (password
 * confirmation, 2FA challenge) works without re-querying LMS on every
 * request. The next successful login refreshes the hash if it has rotated
 * upstream — the LMS remains the identity master.
 *
 * Invoked from `FortifyServiceProvider::configureAuthentication()` after a
 * successful password verification against the LMS.
 */
final class UpsertPasUserFromLms
{
    public function __invoke(LmsUser $lmsUser): User
    {
        $now = Carbon::now();

        // Pin the pas_users row to the tenant whose LMS just verified the
        // password. This is the source of truth for non-super-admin tenant
        // resolution: ApplyTenantOverride uses pas_users.school_id to lock
        // ordinary users to their school regardless of the request's
        // subdomain / path / header. Falls back to the existing value if
        // somehow no tenant is current (defensive — shouldn't happen because
        // SchoolTenantFinder always resolves something).
        $currentTenant = Tenant::current();
        $tenantSchoolId = $currentTenant?->getKey();

        // Primary lookup — composite (school_id, lms_user_id) is the
        // authoritative identifier for an LMS user's payroll mirror
        // post-D.3. Two distinct LMS deployments can both have a user at
        // internal id=5; the composite key keeps their pas_users rows
        // distinct.
        $existing = null;
        if ($tenantSchoolId !== null) {
            $existing = DB::table('pas_users')
                ->where('school_id', $tenantSchoolId)
                ->where('lms_user_id', $lmsUser->id)
                ->first();
        }

        // Legacy fallback — pre-D.3 rows may have lms_user_id set but
        // school_id NULL, or vice versa. Match by email which is still
        // globally unique. Used during the transition window for rows
        // seeded before the tenant-aware upsert wired up.
        if ($existing === null) {
            $existing = DB::table('pas_users')
                ->where('email', $lmsUser->email)
                ->first();
        }

        $payload = [
            'lms_user_id' => $lmsUser->id,
            'school_id' => $tenantSchoolId ?? $existing->school_id ?? null,
            'name' => (string) ($lmsUser->getAttribute('full_name') ?? $lmsUser->getAttribute('name') ?? ''),
            'email' => (string) $lmsUser->email,
            'password' => (string) $lmsUser->password,
            'remember_token' => $lmsUser->getAttribute('remember_token'),
            'email_verified_at' => $lmsUser->getAttribute('email_verified_at'),
            'updated_at' => $now,
        ];

        if ($existing === null) {
            // No `id` preset — let auto-increment assign. The new row's id
            // will not match $lmsUser->id; that's fine because Auth::id()
            // returns pas_users.id (whatever it is) at write time, so all
            // subsequent FK writes from pas_* into pas_users(id) continue
            // to work.
            $payload['created_at'] = $now;
            // Fortify-owned columns are left at their schema defaults (null)
            // on first insert; the user enrolls into 2FA / verifies email
            // through the normal Fortify flows after login.
            $resolvedId = (int) DB::table('pas_users')->insertGetId($payload);
        } else {
            DB::table('pas_users')->where('id', $existing->id)->update($payload);
            $resolvedId = (int) $existing->id;
        }

        $user = User::query()->find($resolvedId);

        if ($user === null) {
            throw new RuntimeException(
                "pas_users upsert failed for LMS user id {$lmsUser->id}",
            );
        }

        return $user;
    }
}
