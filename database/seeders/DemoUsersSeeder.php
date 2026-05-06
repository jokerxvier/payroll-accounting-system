<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds four demo accounts for the login page's "Demo as X" buttons.
 *
 * Idempotent — re-running will not create duplicates and will not trample
 * LMS-owned identity columns. New rows are created with the full column
 * set; existing rows only have their `password` and `email_verified_at`
 * refreshed (both are on App\Models\User::$lmsWritableColumns).
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
 *   - auditor           → role_id null (listener returns early when
 *                                       role_id is NULL — seeded
 *                                       Spatie role survives login)
 *
 * The "auditor" path produces a logged warning per login (the listener's
 * existing behavior for null/non-allowlisted role_ids). Acceptable for a
 * demo-only account. A production auditor would be onboarded through the
 * LMS role mapping.
 *
 * Depends on RoleSeeder having run first (the four payroll roles must
 * already exist in pas_roles). RoleSeeder is invoked at the top of run()
 * and is itself idempotent.
 */
final class DemoUsersSeeder extends Seeder
{
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

        // The shared LMS `users` table (dev/prod) doesn't carry an
        // `email_verified_at` column — that column is a Laravel/Fortify
        // convention only present in the test-env users schema (sqlite
        // mirror at database/migrations/testing/0001_01_01_create_test_users_table.php).
        // The User model intentionally doesn't implement MustVerifyEmail so
        // Fortify never reads this column at runtime; we only set it when
        // it physically exists so the seeder works in both environments.
        $hasEmailVerifiedAt = Schema::hasColumn('users', 'email_verified_at');

        foreach (self::ACCOUNTS as $account) {
            $user = User::query()->where('email', $account['email'])->first();

            if ($user === null) {
                // Fresh INSERT — App\Models\User::save() permits all columns
                // for new rows. Bypass $fillable for non-fillable columns.
                $user = new User;
                $user->email = $account['email'];
                $user->full_name = $account['full_name'];
                $user->password = Hash::make('password');
                $user->role_id = $account['lms_role_id'];

                if ($hasEmailVerifiedAt) {
                    $user->email_verified_at = now();
                }

                $user->save();
            } else {
                // Existing row — only allowlisted columns may be updated.
                // Rotate the password (and refresh email_verified_at where
                // the column exists); leave full_name and role_id alone
                // (LMS owns identity).
                $user->password = Hash::make('password');

                if ($hasEmailVerifiedAt) {
                    $user->email_verified_at = now();
                }

                $user->save();
            }

            $user->syncRoles([$account['role']]);
        }

        // Refresh the permission cache so subsequent role lookups in the
        // same process pick up the synced roles immediately.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
