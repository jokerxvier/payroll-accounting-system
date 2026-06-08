<?php

declare(strict_types=1);

namespace App\Multitenancy\Tasks;

use App\Models\Pas\School;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

/**
 * Rebinds `database.connections.lms` to the resolved tenant's credentials.
 *
 * CRITICAL invariant — touches ONLY the `lms` connection. The default
 * `mysql` connection is the central payroll DB and must remain stable
 * across the request lifecycle. Plan-2.md architecture promise #1.
 *
 * Snapshots the .env-derived defaults at construction so `forgetCurrent`
 * is deterministic regardless of intervening writes to config().
 */
final class SwitchLmsConnection implements SwitchTenantTask
{
    /** @var array<string, mixed> */
    private array $defaults;

    public function __construct()
    {
        // Capture the original .env-resolved config snapshot so we can
        // restore precisely what was there before any tenant swap.
        $this->defaults = (array) config('database.connections.lms', []);
    }

    public function makeCurrent(IsTenant $tenant): void
    {
        if (! $tenant instanceof School) {
            // Defensive — only School tenants are valid in this app.
            return;
        }

        config([
            'database.connections.lms.host' => $tenant->lms_db_host,
            'database.connections.lms.port' => $tenant->lms_db_port,
            'database.connections.lms.database' => $tenant->lms_db_database,
            'database.connections.lms.username' => $tenant->lms_db_username,
            // The `encrypted` cast may yield null for legacy / partial rows;
            // Laravel's mysql connector accepts an empty string but not null.
            'database.connections.lms.password' => $tenant->lms_db_password ?? '',
            'database.connections.lms.charset' => $tenant->lms_db_charset,
        ]);

        DB::purge('lms');
    }

    public function forgetCurrent(): void
    {
        config(['database.connections.lms' => $this->defaults]);
        DB::purge('lms');
    }
}
