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
 * Id-preserving: `pas_users.id` always equals `users.id` (from LMS) for
 * users that originate in the LMS. This invariant is load-bearing — the
 * five FKs from `pas_*` to `users(id)` will be redirected to `pas_users(id)`
 * in Phase A.3 without any data migration.
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

        $existing = DB::table('pas_users')->where('id', $lmsUser->id)->first();

        // Pin the pas_users row to the tenant whose LMS just verified the
        // password. This is the source of truth for non-super-admin tenant
        // resolution: ApplyTenantOverride uses pas_users.school_id to lock
        // ordinary users to their school regardless of the request's
        // subdomain / path / header. Falls back to the existing value if
        // somehow no tenant is current (defensive — shouldn't happen because
        // SchoolTenantFinder always resolves something).
        $currentTenant = Tenant::current();
        $tenantSchoolId = $currentTenant?->getKey();

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
            $payload['id'] = $lmsUser->id;
            $payload['created_at'] = $now;
            // Fortify-owned columns are left at their schema defaults (null)
            // on first insert; the user enrolls into 2FA / verifies email
            // through the normal Fortify flows after login.
            DB::table('pas_users')->insert($payload);
        } else {
            DB::table('pas_users')->where('id', $lmsUser->id)->update($payload);
        }

        $user = User::query()->find($lmsUser->id);

        if ($user === null) {
            throw new RuntimeException(
                "pas_users upsert failed for LMS user id {$lmsUser->id}",
            );
        }

        return $user;
    }
}
