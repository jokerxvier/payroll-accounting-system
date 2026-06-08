<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pas_schools.lms_db_password` was created NOT NULL in B.1 but the
 * StoreSchoolRequest / TestSchoolConnectionRequest validation rules
 * accept null (passwordless MySQL is a valid tenant configuration —
 * common in local dev). Without this alter, creating a school via the
 * admin UI with no password 500s with a 1048 integrity-constraint
 * violation.
 *
 * Allow NULL; the encrypted cast roundtrips null cleanly. The store
 * controller and the SwitchLmsConnection task already coerce null to
 * empty string when the credential reaches the mysql connector, so
 * runtime semantics are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_schools')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite collapses TEXT/VARCHAR to a single TEXT affinity and
            // doesn't enforce NOT NULL the way MySQL does — the test suite
            // already lets null through. Skip the alter to avoid pulling in
            // doctrine/dbal under tests.
            return;
        }

        DB::statement('ALTER TABLE `pas_schools` MODIFY `lms_db_password` TEXT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_schools')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Restore NOT NULL. Any null values would block this — but the
        // forward migration only ran in dev/staging/prod where rows are
        // recent, and the only seeded row carries a non-null encrypted
        // value via the SchoolSeeder's config-derived default.
        DB::statement('ALTER TABLE `pas_schools` MODIFY `lms_db_password` TEXT NOT NULL');
    }
};
