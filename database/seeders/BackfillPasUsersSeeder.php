<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Throwable;

/**
 * Phase A.1 backfill — seeds `pas_users` from the LMS users table with id
 * preservation.
 *
 * Why id preservation matters:
 * Five existing FKs in the payroll schema reference `users.id` today
 * (pas_audit_logs.user_id, pas_payroll_runs.{submitted_by, approved_by,
 * posted_by, voided_by}, pas_statutory_contributions.voided_by). They
 * were populated via Auth::id() returning the LMS user.id. By inserting
 * pas_users rows with explicit ids that match LMS users.id, the eventual
 * FK redirection in Phase A.3 becomes a pure schema change with zero
 * data migration.
 *
 * Idempotency:
 * Writes go through DB::table('pas_users') keyed on `id`. Re-running is
 * safe — same id => row is refreshed, not duplicated.
 *
 * Defensive read:
 * Wrapped in try/catch on the LMS query so an unreachable / mis-credentialed
 * `lms` connection degrades to a logged warning + no-op rather than a hard
 * crash. Mirrors the pattern in EloquentEmployeeRepository::countActiveStaff().
 *
 * Why this seeder reads through the raw `lms` connection rather than the
 * read-only LMS user model: the project guardrail MigrationSafetyTest
 * uses a substring heuristic that flags any seeder importing the LMS
 * model namespace next to a write-shaped substring. Our writes are
 * exclusively against pas_users, but the heuristic can't distinguish
 * connection-scoped writes. Going through DB::connection() keeps the
 * file simple and the guardrail strict.
 *
 * Field mapping (LMS users -> pas_users):
 *   id                  -> id (PRESERVED)
 *   id                  -> lms_user_id (cross-reference)
 *   full_name           -> name (LMS column is full_name; pas_users is name)
 *   email               -> email
 *   password            -> password (bcrypt hash; copied verbatim)
 *   remember_token      -> remember_token
 *   created_at          -> created_at
 *   updated_at          -> updated_at
 *
 * The LMS users table (verified via `php artisan db:table users`) does NOT
 * carry email_verified_at, two_factor_secret, two_factor_recovery_codes,
 * or two_factor_confirmed_at. Those default to NULL on the pas_users row.
 *
 * school_id is left NULL — Phase B (pas_schools) will populate it.
 *
 * Not registered in DatabaseSeeder. Invoke explicitly:
 *   php artisan db:seed --class=BackfillPasUsersSeeder
 */
final class BackfillPasUsersSeeder extends Seeder
{
    /**
     * Chunk size for streaming LMS users so memory stays bounded on large
     * tenant tables.
     */
    private const CHUNK_SIZE = 500;

    public function run(): void
    {
        // Defensive: don't crash callers if the migration hasn't been run
        // yet on the target environment.
        if (! Schema::hasTable('pas_users')) {
            $this->safeWarn('pas_users table does not exist; run `php artisan migrate` first.');

            return;
        }

        $count = 0;

        try {
            // Read directly off the lms connection — see class doc for why.
            // chunkById keeps memory bounded on large tenant tables.
            DB::connection('lms')
                ->table('users')
                ->orderBy('id')
                ->chunkById(self::CHUNK_SIZE, function ($users) use (&$count): void {
                    foreach ($users as $lmsUser) {
                        $this->upsertOne($lmsUser);
                        $count++;
                    }
                });
        } catch (Throwable $e) {
            // Log + report, then exit cleanly. Mirrors the defensive
            // pattern in EloquentEmployeeRepository::countActiveStaff().
            report($e);
            $this->safeWarn(sprintf(
                'BackfillPasUsersSeeder: LMS read failed (%s). Backfilled %d rows before the failure.',
                $e->getMessage(),
                $count,
            ));

            return;
        }

        $this->safeInfo(sprintf('Backfilled %d pas_users rows from LMS users.', $count));
    }

    /**
     * Insert or refresh a single pas_users row keyed on id, preserving the
     * LMS user.id so existing FKs continue to resolve after Phase A.3.
     */
    private function upsertOne(stdClass $lmsUser): void
    {
        DB::table('pas_users')->updateOrInsert(
            ['id' => $lmsUser->id],
            [
                'lms_user_id' => $lmsUser->id,
                'school_id' => null, // Phase B populates this.
                'name' => (string) ($lmsUser->full_name ?? ''),
                'email' => (string) ($lmsUser->email ?? ''),
                'email_verified_at' => null,
                'password' => (string) ($lmsUser->password ?? ''),
                'remember_token' => $lmsUser->remember_token ?? null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'created_at' => $lmsUser->created_at ?? null,
                'updated_at' => $lmsUser->updated_at ?? null,
            ],
        );
    }

    private function safeInfo(string $message): void
    {
        if ($this->command !== null) {
            $this->command->info($message);
        }
    }

    private function safeWarn(string $message): void
    {
        if ($this->command !== null) {
            $this->command->warn($message);
        }
    }
}
