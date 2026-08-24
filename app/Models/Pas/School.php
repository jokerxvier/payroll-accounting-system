<?php

declare(strict_types=1);

namespace App\Models\Pas;

use App\Concerns\Auditable;
use Database\Factories\Pas\SchoolFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Tenant registry row — one per LMS deployment that this central payroll
 * instance serves. Extends Spatie's Tenant base so the package's tenant
 * resolution + queue + scheduler integration "just works" once Phase C
 * registers a TenantFinder and the SwitchLmsConnection task.
 *
 * Encryption: `lms_db_password` round-trips through Laravel's `encrypted`
 * cast so credentials are never readable in tinker / DB dumps. Tied to
 * APP_KEY — rotating APP_KEY without re-encrypting will brick stored
 * passwords. Standard Laravel encryption caveat.
 *
 * The `getDatabaseName()` override returns `lms_db_database` so Spatie's
 * default tenant tasks (we don't register them, but Phase C's custom
 * SwitchLmsConnection task reads via the same accessor) can resolve the
 * LMS DB name uniformly.
 *
 * Connection: lives on the default `mysql` connection (the central
 * payroll DB). The Spatie base class would otherwise route reads through
 * a "landlord" connection — we intentionally use the default by leaving
 * `landlord_database_connection_name` null in config/multitenancy.php.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property ?string $domain
 * @property string $lms_db_host
 * @property int $lms_db_port
 * @property string $lms_db_database
 * @property string $lms_db_username
 * @property string $lms_db_password
 * @property string $lms_db_charset
 * @property bool $is_active
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class School extends Tenant
{
    use Auditable;

    /** @use HasFactory<SchoolFactory> */
    use HasFactory;

    protected $table = 'pas_schools';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'domain',
        'lms_db_host',
        'lms_db_port',
        'lms_db_database',
        'lms_db_username',
        'lms_db_password',
        'lms_db_charset',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lms_db_password' => 'encrypted',
            'is_active' => 'boolean',
            'lms_db_port' => 'integer',
        ];
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return SchoolFactory::new();
    }

    /**
     * Returns the LMS database name for this tenant. Phase C's
     * SwitchLmsConnection task reads this when rebinding the `lms`
     * connection for the resolved tenant.
     */
    public function getDatabaseName(): string
    {
        return (string) $this->lms_db_database;
    }

    /**
     * Keep the tenant's LMS database password out of the audit trail.
     *
     * The column is `encrypted` at rest, but an audit row is a second copy
     * of the credential in a table that auditors can export to CSV and PDF.
     * A tenant's database password has no business travelling with a change
     * log, so it is dropped from both the before and after snapshots — the
     * fact that it changed is still visible, because `updated_at` moves and
     * the surrounding columns are recorded.
     *
     * @return list<string>
     */
    public function auditExclude(): array
    {
        return ['lms_db_password'];
    }
}
