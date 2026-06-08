<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * Phase A.2 of the multi-tenant refactor: User now lives on `pas_users`.
 *
 * For tests that exercise the new auth flow (which expects an LMS user
 * record to look up by email + verify the password against), this factory
 * also mirrors the row into the test sqlite `users` table when that table
 * exists (it ships with the testing migration at
 * database/migrations/testing/0001_01_01_create_test_users_table.php).
 *
 * The mirror is created only when the testing `users` table exists, so the
 * factory is safe in environments that don't load the testing migrations
 * (e.g., production seeding paths). In those environments callers are
 * responsible for ensuring an LMS user exists separately.
 *
 * Identity invariant: `pas_users.id == users.id` (the LMS-side mirror is
 * created with the same id), preserving the same id-equality the Phase A.1
 * backfill establishes for production.
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Default LMS role_id used when mirroring into the test `users` table.
     * 4 = Teacher (allowlisted; falls through to the 'employee' Spatie role
     * via AssignPayrollRoleOnLogin). Override per-call with withLmsRole().
     */
    private const DEFAULT_LMS_ROLE_ID = 4;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // role_id is NOT a pas_users column; the LMS mirror written by
            // configure() carries it.
        ];
    }

    /**
     * Default lifecycle hook: every freshly created pas_users row gets
     * mirrored into the test sqlite `users` table (when present) with the
     * default LMS role_id, and lms_user_id is backfilled on the pas_users
     * row. State helpers (withLmsRole, withoutLmsMirror) attach additional
     * afterCreating callbacks that override or skip the mirror.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $this->mirrorToLmsUsers($user, self::DEFAULT_LMS_ROLE_ID);
            $this->backfillLmsUserId($user);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Override the LMS mirror's role_id. Re-runs the mirror with the new
     * role_id (updateOrInsert is idempotent on `id`, so the latest call
     * wins).
     */
    public function withLmsRole(int $roleId): static
    {
        return $this->afterCreating(function (User $user) use ($roleId): void {
            $this->mirrorToLmsUsers($user, $roleId);
        });
    }

    /**
     * Skip mirroring into the LMS users table. Useful for tests that
     * deliberately exercise the "pas_users row without an LMS counterpart"
     * code paths (e.g., the registration flow).
     *
     * Removes any prior mirror row created by configure()'s default hook
     * and clears the lms_user_id cross-reference.
     */
    public function withoutLmsMirror(): static
    {
        return $this->afterCreating(function (User $user): void {
            if (Schema::hasTable('users')) {
                DB::table('users')->where('id', $user->id)->delete();
            }
            DB::table('pas_users')
                ->where('id', $user->id)
                ->update(['lms_user_id' => null]);
        });
    }

    /**
     * Mirrors the pas_users row into the LMS-shaped `users` table on the
     * test sqlite. No-op when the table is absent (production / non-test
     * environments). Idempotent: keyed on `id`, so repeat calls overwrite
     * (last role_id wins — that's how withLmsRole() composes).
     */
    private function mirrorToLmsUsers(User $user, int $roleId): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        DB::table('users')->updateOrInsert(
            ['id' => $user->id],
            [
                'full_name' => (string) $user->name,
                'email' => (string) $user->email,
                'password' => (string) $user->getAttributes()['password'],
                'remember_token' => $user->getAttributes()['remember_token'] ?? null,
                'email_verified_at' => $user->getAttributes()['email_verified_at'] ?? null,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Backfill `pas_users.lms_user_id = pas_users.id` so the
     * AssignPayrollRoleOnLogin listener can resolve the LMS row by id.
     * Uses a raw update because lms_user_id is intentionally not in
     * `$fillable` on the User model. Sets the attribute on the in-memory
     * model too so callers don't have to refresh().
     */
    private function backfillLmsUserId(User $user): void
    {
        DB::table('pas_users')
            ->where('id', $user->id)
            ->update(['lms_user_id' => $user->id]);

        $user->setAttribute('lms_user_id', $user->id);
        $user->syncOriginalAttribute('lms_user_id');
    }
}
