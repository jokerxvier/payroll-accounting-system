<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds four demo accounts for the login page's "Demo as X" buttons.
 *
 * Phase A.2 update: identity now lives in two places — the LMS-owned
 * `users` table (canonical, the password is verified against it during
 * login) and the payroll-DB-owned `pas_users` table (Fortify session +
 * 2FA + email verification state). This seeder writes both, id-preserved,
 * mirroring exactly what the production login flow does on first login.
 *
 * Idempotent — re-running keeps the same row ids and refreshes only the
 * password + email_verified_at. Names and role_ids on the LMS side are
 * not rewritten so a manual LMS-side change is preserved.
 *
 * NOT registered in DatabaseSeeder::run() — invoke explicitly:
 *
 *     php artisan db:seed --class=DemoUsersSeeder
 *
 * The four LMS role_id values below are picked so that the on-login
 * role-sync listener (App\Listeners\AssignPayrollRoleOnLogin) preserves
 * the seeded payroll role on subsequent logins:
 *
 *   - super-admin       → role_id 1   (LMS "Super admin",  mapped)
 *   - payroll-officer   → role_id 6   (LMS "Accountant",   mapped)
 *   - hr                → role_id 15  (LMS "HR Officer",   mapped)
 *   - auditor           → role_id null (listener logs a warning + returns
 *                                       early; seeded Spatie role survives)
 *
 * Depends on RoleSeeder having run first (the four payroll roles must
 * already exist in pas_roles). RoleSeeder is invoked at the top of run()
 * and is itself idempotent.
 */
final class DemoUsersSeeder extends Seeder
{
    /**
     * Stable id allocations so the four demo accounts always get the same
     * pas_users.id (and the same lms users.id mirror in tests). Picked in
     * a high-numbered range to avoid collision with real LMS row ids that
     * monotonically grow from 1.
     */
    private const DEMO_ID_BASE = 9_000_001;

    /**
     * @var list<array{email: string, full_name: string, role: string, lms_role_id: int|null}>
     */
    private const ACCOUNTS = [
        [
            'email' => 'super-admin@demo.test',
            'full_name' => 'Demo Super Admin',
            'role' => 'super-admin',
            'lms_role_id' => 1,
        ],
        [
            'email' => 'payroll-officer@demo.test',
            'full_name' => 'Demo Payroll Officer',
            'role' => 'payroll-officer',
            'lms_role_id' => 6,
        ],
        [
            'email' => 'hr@demo.test',
            'full_name' => 'Demo HR',
            'role' => 'hr',
            'lms_role_id' => 15,
        ],
        [
            'email' => 'auditor@demo.test',
            'full_name' => 'Demo Auditor',
            'role' => 'auditor',
            // role_id NULL → AssignPayrollRoleOnLogin returns early without
            // touching the user's roles. Without this, the listener would
            // resync auditor to "employee" on first login.
            'lms_role_id' => null,
        ],
    ];

    public function run(): void
    {
        // Ensure the role taxonomy exists. RoleSeeder is itself idempotent.
        $this->call(RoleSeeder::class);

        $now = Carbon::now();

        foreach (self::ACCOUNTS as $i => $account) {
            // Reuse an existing row's id if either side already has a row
            // for this email (typical for dev environments that ran the
            // pre-A.2 seeder + the A.1 backfill). Otherwise allocate a
            // stable high id from the demo range.
            $existingId = $this->resolveExistingId($account['email'])
                ?? (self::DEMO_ID_BASE + $i);

            $passwordHash = Hash::make('password');

            $this->upsertLmsUser($existingId, $account, $passwordHash, $now);
            $this->upsertPasUser($existingId, $account, $passwordHash, $now);

            User::query()->find($existingId)?->syncRoles([$account['role']]);
        }

        // Refresh the permission cache so subsequent role lookups in the
        // same process pick up the synced roles immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Look up the existing pas_users.id (preferred) or LMS users.id for the
     * given email so the seeder updates the established row rather than
     * inserting a duplicate.
     */
    private function resolveExistingId(string $email): ?int
    {
        $pasId = DB::table('pas_users')->where('email', $email)->value('id');
        if ($pasId !== null) {
            return (int) $pasId;
        }

        if (Schema::hasTable('users')) {
            $lmsId = DB::table('users')->where('email', $email)->value('id');
            if ($lmsId !== null) {
                return (int) $lmsId;
            }
        }

        return null;
    }

    /**
     * Upsert the LMS-side `users` row that the new login flow looks up by
     * email. Today the LMS database physically shares the same MySQL with
     * the payroll DB (single-tenant Phase 1), so the row goes through the
     * **default** connection — which in dev is `mysql payroll_db` and in
     * tests is the sqlite in-memory mirror that
     * `database/migrations/testing/0001_01_01_create_test_users_table.php`
     * creates. Both expose the same `users` table shape.
     *
     * Once Phase A.3 lands and the LMS DB physically splits from payroll,
     * this seeder is no longer responsible for seeding LMS data — the
     * production LMS already has its real user rows. The path here is
     * preserved for dev/test parity only.
     *
     * No-op when the `users` table is absent on the default connection
     * (e.g., minimal CI environments without the testing migration).
     *
     * @param  array{email: string, full_name: string, role: string, lms_role_id: int|null}  $account
     */
    private function upsertLmsUser(int $id, array $account, string $passwordHash, Carbon $now): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $existing = DB::table('users')->where('id', $id)->first();

        $payload = [
            'email' => $account['email'],
            'password' => $passwordHash,
            'role_id' => $account['lms_role_id'],
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('users', 'full_name')) {
            $payload['full_name'] = $account['full_name'];
        }

        if (Schema::hasColumn('users', 'email_verified_at')) {
            $payload['email_verified_at'] = $now;
        }

        if ($existing === null) {
            $payload['id'] = $id;
            $payload['created_at'] = $now;
            DB::table('users')->insert($payload);
        } else {
            DB::table('users')->where('id', $id)->update($payload);
        }
    }

    /**
     * Upsert the pas_users row id-preserved (matching the LMS row's id),
     * mirroring exactly what UpsertPasUserFromLms does on first login.
     *
     * @param  array{email: string, full_name: string, role: string, lms_role_id: int|null}  $account
     */
    private function upsertPasUser(int $id, array $account, string $passwordHash, Carbon $now): void
    {
        $existing = DB::table('pas_users')->where('id', $id)->first();

        $payload = [
            'lms_user_id' => $id,
            'name' => $account['full_name'],
            'email' => $account['email'],
            'password' => $passwordHash,
            'email_verified_at' => $now,
            'updated_at' => $now,
        ];

        if ($existing === null) {
            $payload['id'] = $id;
            $payload['created_at'] = $now;
            DB::table('pas_users')->insert($payload);
        } else {
            DB::table('pas_users')->where('id', $id)->update($payload);
        }
    }
}
