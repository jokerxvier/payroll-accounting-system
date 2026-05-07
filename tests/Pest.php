<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
