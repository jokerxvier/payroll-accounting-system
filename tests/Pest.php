<?php

use App\Models\Pas\School;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SchoolSeeder;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Seed the 5 payroll roles into the freshly migrated DB so any code
        // path that tries to look up / assign a role (e.g. the login listener
        // that maps LMS role_id -> payroll role) works without each test
        // having to seed manually.
        $this->seed(RoleSeeder::class);

        // Phase C.1 — Spatie's NeedsTenant middleware now runs on the web
        // group. SchoolTenantFinder falls through to the `slug=default`
        // school when PAYROLL_MULTI_TENANT=false, so every HTTP test needs
        // that row to exist or middleware aborts. Seed once, idempotently.
        $this->seed(SchoolSeeder::class);

        // Re-point the seeded default school's lms_db_* columns at the
        // currently-resolved `lms` connection config, NOT the `mysql`
        // (default) connection's database. The production seeder mirrors
        // mysql by design (production has lms creds in their own LMS_DB_*
        // env block), but in test env DB_DATABASE=:memory: which is invalid
        // for a mysql-driver lms connection. Tests using useLmsSqliteMirror
        // re-update these to ':memory:' under driver=sqlite afterwards.
        // Use Eloquent (not query-builder update) so the `encrypted` cast
        // on lms_db_password round-trips correctly.
        $lms = (array) config('database.connections.lms', []);
        $defaultSchool = School::query()->where('slug', 'default')->first();
        if ($defaultSchool !== null) {
            $defaultSchool->fill([
                'lms_db_host' => (string) ($lms['host'] ?? '127.0.0.1'),
                'lms_db_port' => (int) ($lms['port'] ?? 3306),
                'lms_db_database' => (string) ($lms['database'] ?? 'payroll_db'),
                'lms_db_username' => (string) ($lms['username'] ?? 'root'),
                'lms_db_password' => (string) ($lms['password'] ?? ''),
                'lms_db_charset' => (string) ($lms['charset'] ?? 'utf8mb4'),
            ]);
            $defaultSchool->save();

            // Spatie's Multitenancy::start() short-circuits when running in
            // console (`! $this->app->runningInConsole()`), so its
            // auto-resolution pipeline never fires during phpunit-driven HTTP
            // tests. Bind the default school as the current tenant manually
            // so NeedsTenant middleware sees a current tenant on
            // `$this->get(...)` calls. SwitchLmsConnection runs as part of
            // makeCurrent and rewrites `database.connections.lms.*` to the
            // school's columns — which now mirror the .env-derived lms
            // defaults — so behavior is unchanged.
            $defaultSchool->makeCurrent();

            // Spatie's MakeQueueTenantAwareAction calls `forgetCurrent()`
            // after every non-tenant-aware job (which is every job in C.1,
            // since `queues_are_tenant_aware_by_default = false`). Under
            // sync queues this fires inside a test method, after which any
            // subsequent `$this->get(...)` aborts with NoCurrentTenant.
            // Restore the tenant on JobProcessed so tests using sync jobs
            // can continue making HTTP requests in the same method.
            Event::listen(JobProcessed::class, function () use ($defaultSchool): void {
                $defaultSchool->makeCurrent();
            });
        }
    })
    ->in('Feature', 'Browser');

/*
 * Phase A.2 auth-test isolation helper.
 *
 * The new Fortify auth flow (FortifyServiceProvider::configureAuthentication)
 * looks up the LMS user by email and verifies the supplied password against
 * the LMS row. The `lms` connection points at MySQL `payroll_db` by default
 * (live LMS data — needed by EmployeeRepositoryTest, LmsReadOnlyTest,
 * BackfillPasUsersSeederTest, …), so an auth test using a factory-
 * created sqlite user would never find its row through the real LMS
 * connection.
 *
 * Tests that exercise the new auth flow (login, role-sync listener, demo
 * seeders that simulate the LMS-side identity row) call useLmsSqliteMirror()
 * in their beforeEach to point the `lms` connection at the same sqlite as
 * the default connection. The shared in-memory DB means LmsUser::query()
 * reads the test mirror table created by
 * database/migrations/testing/0001_01_01_create_test_users_table.php.
 *
 * Tests that need real LMS MySQL data (BackfillPasUsersSeederTest,
 * LmsReadOnlyTest, EmployeeRepositoryTest, …) simply don't call this
 * helper.
 */
function useLmsSqliteMirror(): void
{
    config([
        'database.connections.lms' => array_merge(
            config('database.connections.lms', []),
            ['driver' => 'sqlite', 'database' => ':memory:'],
        ),
    ]);
    DB::purge('lms');

    // The default sqlite connection in tests is also `:memory:`, so a
    // separate purge would create a *different* in-memory DB on next
    // resolve. Bind the same PDO instance so both connection names share
    // schema + data.
    $defaultPdo = DB::connection()->getPdo();
    DB::connection('lms')->setPdo($defaultPdo);
    DB::connection('lms')->setReadPdo($defaultPdo);

    // Phase C.1 — Spatie's NeedsTenant middleware fires SwitchLmsConnection
    // on every web request, which calls DB::purge('lms') after rewriting the
    // connection's host/port/database/username/password/charset to the
    // resolved school's columns. The driver is intentionally NOT rewritten,
    // so sqlite-as-lms survives the swap. But the shared PDO does not — the
    // purge discards the PDO instance and a fresh resolve would create a
    // separate in-memory DB.
    //
    // Mirror the default school row's lms_db_* columns onto the in-memory
    // sqlite database so the post-middleware config still points at
    // ':memory:', and re-bind the shared PDO immediately after the request
    // by hooking ConnectionEstablished on the next 'lms' resolve.
    School::query()->where('slug', 'default')->update([
        'lms_db_host' => '127.0.0.1',
        'lms_db_port' => 3306,
        'lms_db_database' => ':memory:',
        'lms_db_username' => 'root',
        'lms_db_password' => '',
        'lms_db_charset' => 'utf8mb4',
    ]);

    Event::listen(
        ConnectionEstablished::class,
        function ($event) use ($defaultPdo): void {
            if ($event->connectionName === 'lms') {
                $event->connection->setPdo($defaultPdo);
                $event->connection->setReadPdo($defaultPdo);
            }
        },
    );
}

// Auto-apply the LMS sqlite mirror to suites that always exercise the new
// Fortify flow end-to-end. Other suites opt in per-file (see, e.g.,
// Feature/Seeders/DemoUsersSeederTest.php beforeEach).
pest()->beforeEach(function (): void {
    useLmsSqliteMirror();
})->in('Feature/Auth', 'Feature/Settings');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
