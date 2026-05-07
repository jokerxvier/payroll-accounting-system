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

        // Pull from the resolved `database.connections.mysql.*` config so the
        // seeder works under cached config in staging / production. Larastan
        // flags raw env() calls outside `config/` for exactly this reason.
        $mysql = (array) config('database.connections.mysql', []);

        School::query()->updateOrCreate(
            ['slug' => 'default'],
            [
                'name' => $appName !== '' ? $appName : 'Default School',
                'domain' => null,
                'lms_db_host' => (string) ($mysql['host'] ?? '127.0.0.1'),
                'lms_db_port' => (int) ($mysql['port'] ?? 3306),
                'lms_db_database' => (string) ($mysql['database'] ?? 'payroll_db'),
                'lms_db_username' => (string) ($mysql['username'] ?? 'root'),
                'lms_db_password' => (string) ($mysql['password'] ?? ''),
                'lms_db_charset' => 'utf8mb4',
                'is_active' => true,
            ],
        );
    }
}
