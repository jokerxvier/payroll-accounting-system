<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D.3 — cross-LMS collision safety on pas_users.
 *
 * Two distinct LMS deployments can both have a user at internal id=5.
 * Without a tenant-scoped uniqueness constraint, the upsert in
 * UpsertPasUserFromLms could overwrite school A's John when school B's
 * Jane logs in (both at lms_user_id=5). The composite unique (school_id,
 * lms_user_id) keeps the rows distinct.
 *
 * MySQL treats NULL as distinct in composite uniques, so:
 *   (NULL, NULL)    — multiple platform-admin rows allowed (their email
 *                     unique still enforces single-row-per-email)
 *   (school_id, NULL) — non-LMS users in a school (rare; allowed)
 *   (school_id, lms_user_id) — exactly one row per LMS user per school
 *                              (the constraint we want)
 *
 * Backfill: set school_id = default school's id for any existing
 * lms-derived rows (lms_user_id IS NOT NULL) currently null. The
 * auto-fill on login will keep this in sync from now on, but rows that
 * pre-date the auto-fill (seeded but never logged in) need this one-shot.
 *
 * Reversible — down() drops the composite unique. The existing email
 * unique stays in both directions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_users')) {
            return;
        }

        $defaultSchoolId = DB::table('pas_schools')
            ->where('slug', 'default')
            ->value('id');

        // Backfill school_id for LMS-derived rows that haven't logged in
        // yet (the auto-fill on login covers post-deploy logins).
        if ($defaultSchoolId !== null) {
            DB::table('pas_users')
                ->whereNull('school_id')
                ->whereNotNull('lms_user_id')
                ->update(['school_id' => $defaultSchoolId]);
        }

        // Add the composite unique. SQLite handles this naturally; MySQL
        // also accepts it (NULL-distinct semantics).
        Schema::table('pas_users', function (Blueprint $table): void {
            $table->unique(['school_id', 'lms_user_id'], 'pas_users_school_lms_user_uq');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_users')) {
            return;
        }

        Schema::table('pas_users', function (Blueprint $table): void {
            $table->dropUnique('pas_users_school_lms_user_uq');
        });
    }
};
