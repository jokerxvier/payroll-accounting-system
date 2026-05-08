<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 *
 * Phase B.1 — tenant registry rows for tests.
 *
 * Default-state schools point at the dev DB credentials so factory rows
 * "just work" against a local environment. The slug is derived from the
 * generated company name + a numeric suffix so repeated calls don't
 * collide on the unique constraint. `domain` defaults to null; tests
 * that exercise subdomain shape should call `->withDomain(...)`.
 */
class SchoolFactory extends Factory
{
    protected $model = School::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = (string) fake()->company();

        // Pull from the resolved `lms` connection config — School rows
        // describe an LMS source, not the central payroll DB. Post-split
        // (DB_DATABASE != LMS_DB_DATABASE) reading from `mysql` would point
        // factory rows at the payroll DB which has no LMS schema. `mysql`
        // stays as a fallback for bootstrap envs where LMS_DB_* aren't set.
        $lmsDb = (string) (config('database.connections.lms.database')
            ?? config('database.connections.mysql.database')
            ?? 'payroll_db');
        $lmsUser = (string) (config('database.connections.lms.username')
            ?? config('database.connections.mysql.username')
            ?? 'root');
        $lmsPass = (string) (config('database.connections.lms.password')
            ?? config('database.connections.mysql.password')
            ?? '');

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->numerify('###'),
            'domain' => null,
            'lms_db_host' => '127.0.0.1',
            'lms_db_port' => 3306,
            'lms_db_database' => $lmsDb,
            'lms_db_username' => $lmsUser,
            'lms_db_password' => $lmsPass,
            'lms_db_charset' => 'utf8mb4',
            'is_active' => true,
        ];
    }

    /** Set the domain column — used by tests that exercise subdomain finders. */
    public function withDomain(string $domain): self
    {
        return $this->state(fn (): array => ['domain' => $domain]);
    }

    /** Mark the school as inactive — Phase C's scheduler should skip these. */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
