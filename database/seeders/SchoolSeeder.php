<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Pas\School;
use Illuminate\Database\Seeder;

/**
 * Seeds a single "default" school whose LMS connection columns mirror the
 * current `.env` `DB_*` values. Every dev / staging / prod environment
 * gets this row so:
 *
 *   - existing single-tenant data has a school to backfill against
 *     when Phase D adds `school_id` to every tenant-scoped table, and
 *   - Phase C's tenant resolution can fall through to this row when no
 *     subdomain / path-prefix matches (the default school).
 *
 * Idempotent — keyed on `slug = 'default'` via updateOrCreate so re-runs
 * keep the same row id and refresh credentials from `.env`. Production-
 * safe; runs every db:seed.
 */
final class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        $appName = (string) config('app.name');

        // Pull from the resolved `database.connections.lms.*` config — the
        // default school's `lms_db_*` columns describe the LMS source, NOT
        // the central payroll DB. Pre-split (when mysql and lms shared a
        // physical DB) reading from `mysql` worked accidentally. Post-split
        // (DB_DATABASE != LMS_DB_DATABASE) we must read from `lms` or the
        // SwitchLmsConnection task rebinds the lms connection to the payroll
        // DB and login fails because there's no `users` table there.
        //
        // `mysql` config is kept only as host/port/credentials fallback for
        // bootstraps where LMS_DB_* env vars aren't set yet (typical first
        // dev setup before any split happens).
        $lms = (array) config('database.connections.lms', []);
        $mysql = (array) config('database.connections.mysql', []);

        // Seed `domain` to the APP_URL host so the SchoolTenantFinder's
        // subdomain strategy resolves the bare local host to this row when
        // PAYROLL_MULTI_TENANT=true. Without this, a fresh local env that
        // flips the flag hits NoCurrentTenant on every URL — annoying for
        // dev. Production still overrides via the admin UI per real tenant.
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $domain = is_string($appHost) && $appHost !== '' ? $appHost : null;

        School::query()->updateOrCreate(
            ['slug' => 'default'],
            [
                'name' => $appName !== '' ? $appName : 'Default School',
                'domain' => $domain,
                'lms_db_host' => (string) ($lms['host'] ?? $mysql['host'] ?? '127.0.0.1'),
                'lms_db_port' => (int) ($lms['port'] ?? $mysql['port'] ?? 3306),
                'lms_db_database' => (string) ($lms['database'] ?? 'payroll_db'),
                'lms_db_username' => (string) ($lms['username'] ?? $mysql['username'] ?? 'root'),
                'lms_db_password' => (string) ($lms['password'] ?? $mysql['password'] ?? ''),
                'lms_db_charset' => (string) ($lms['charset'] ?? 'utf8mb4'),
                'is_active' => true,
            ],
        );
    }
}
