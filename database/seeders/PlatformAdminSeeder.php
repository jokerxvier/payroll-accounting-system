<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the default platform admin account into pas_users.
 *
 * The platform admin is a payroll-native user (no LMS counterpart) who
 * operates the multi-school deployment. Distinguishing fields:
 *
 *   - lms_user_id = NULL  → identifies a payroll-native row. ApplyTenantOverride
 *     and HandleInertiaRequests gate cross-tenant powers on this column.
 *   - school_id   = NULL  → not pinned to any school. The platform admin
 *     pivots tenants via the in-app switcher (session-stored override).
 *   - Spatie role 'platform-admin' → carries the cross-tenant capability.
 *     The school-scoped 'super-admin' role keeps its existing meaning at
 *     the school level (approve payroll runs, etc.) and is intentionally
 *     NOT assigned here.
 *
 * Authentication path: FortifyServiceProvider::configureAuthentication()
 * verifies the password directly against pas_users.password when the LMS
 * lookup misses (this is the only login path that doesn't go through the
 * LMS, since the platform admin has no LMS counterpart).
 *
 * Idempotent — keyed on email via updateOrInsert. The id is stable
 * (PLATFORM_ADMIN_ID, picked from the same high range as DemoUsersSeeder)
 * so repeat runs touch the same row and existing pas_* references survive.
 *
 * Production note: the seeder hardcodes the dev password 'password'. Ops
 * MUST rotate it via tinker / ResetUserPasswordCommand before staging or
 * production traffic.
 */
final class PlatformAdminSeeder extends Seeder
{
    /**
     * Stable id allocated outside any LMS row range. DemoUsersSeeder uses
     * 9_000_001..9_000_004; this seeder reserves 9_500_001 so the two
     * seeders never collide and reordering them never reassigns ids.
     */
    private const PLATFORM_ADMIN_ID = 9_500_001;

    private const PLATFORM_ADMIN_EMAIL = 'admin@payroll.test';

    public function run(): void
    {
        $now = Carbon::now();
        $passwordHash = Hash::make('password');

        $existing = DB::table('pas_users')
            ->where('email', self::PLATFORM_ADMIN_EMAIL)
            ->first();

        $resolvedId = $existing === null
            ? self::PLATFORM_ADMIN_ID
            : (int) $existing->id;

        $payload = [
            // Payroll-native: no LMS counterpart, no school pin. These two
            // NULLs are load-bearing — every gate that grants cross-tenant
            // powers checks lms_user_id IS NULL alongside the role.
            'lms_user_id' => null,
            'school_id' => null,
            'name' => 'Platform Admin',
            'email' => self::PLATFORM_ADMIN_EMAIL,
            'password' => $passwordHash,
            'email_verified_at' => $now,
            'updated_at' => $now,
        ];

        if ($existing === null) {
            $payload['id'] = $resolvedId;
            $payload['created_at'] = $now;
            DB::table('pas_users')->insert($payload);
        } else {
            DB::table('pas_users')
                ->where('id', $resolvedId)
                ->update($payload);
        }

        // Sync the role AFTER the row exists. syncRoles wipes any prior role
        // assignment, which is what we want — repeat runs guarantee the
        // platform admin has exactly the platform-admin role and nothing else.
        User::query()->find($resolvedId)?->syncRoles(['platform-admin']);

        // Refresh the permission cache so subsequent role lookups in the
        // same process pick up the synced role immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
