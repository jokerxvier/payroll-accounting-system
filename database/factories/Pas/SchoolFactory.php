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

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->numerify('###'),
            'domain' => null,
            'lms_db_host' => '127.0.0.1',
            'lms_db_port' => 3306,
            // Pull from the resolved config (not env() directly) so the
            // factory remains correct under cached config in testing /
            // staging environments — Larastan flags raw env() calls
            // outside `config/` for exactly this reason.
            'lms_db_database' => (string) config('database.connections.mysql.database', 'payroll_db'),
            'lms_db_username' => (string) config('database.connections.mysql.username', 'root'),
            'lms_db_password' => (string) config('database.connections.mysql.password', ''),
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
