<?php

declare(strict_types=1);

use App\Models\Pas\PayPeriod;
use App\Models\Pas\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Browser test for /admin/payroll-runs/create rendered under the
 * Mindhearts tenant.
 *
 * Pinpoints the multi-tenant catalog conversion + the PayPeriod
 * cross-field validation fix end-to-end:
 *   1. SchoolObserver auto-clones default's catalogs onto Mindhearts on
 *      School::create() — the page should render with no missing-config
 *      errors.
 *   2. ApplyTenantOverride middleware locks the HR user to school_id=2,
 *      so PayrollRunController::create() returns only Mindhearts'
 *      pay-periods (school 2's row only, NOT default's).
 *   3. The page renders the Generate-payroll form and the Mindhearts
 *      period in the dropdown trigger label.
 */

it('renders /admin/payroll-runs/create with Mindhearts periods only', function () {
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    // Create the Mindhearts tenant. SchoolObserver fires on `created` and
    // clones default's allowance + deduction-type catalogs into school 2,
    // matching what the dev DB already shows post-migration.
    $mindhearts = School::factory()->create([
        'name' => 'MindHearts',
        'slug' => 'mindhearts',
    ]);

    // Pin Mindhearts as the active tenant in this test process so the
    // BelongsToTenant trait stamps `school_id = mindhearts->id` on every
    // model factory call below.
    $mindhearts->makeCurrent();

    // HR user tied to Mindhearts. Auth + role assignment uses the same
    // pattern as authBrowserAs() in EmployeeShowDeductionsCardTest.
    $user = User::factory()->create();
    $user->syncRoles(['hr']);

    // Lock the user to school 2. ApplyTenantOverride reads
    // pas_users.school_id on every request and calls makeCurrent() on
    // that school — so when the ephemeral browser server fires the
    // request, the middleware switches the LMS connection to Mindhearts
    // and the controller's PayPeriod::query() filters to school 2.
    DB::table('pas_users')->where('id', $user->id)->update([
        'school_id' => $mindhearts->id,
    ]);

    // Open period for Mindhearts that the controller's create() should
    // surface in the dropdown.
    PayPeriod::factory()->monthly(2026, 5)->open()->create([
        'code' => '2026-05-MH',
    ]);

    // Switch to default tenant briefly to seed a period under school 1.
    // Without this, "render Mindhearts only" is meaningless — there's no
    // alternative tenant data to be excluded by.
    $default = School::query()->where('slug', 'default')->firstOrFail();
    $default->makeCurrent();
    PayPeriod::factory()->monthly(2026, 5)->open()->create([
        'code' => '2026-05-DEFAULT',
    ]);

    // Restore Mindhearts as current for the test thread; the request
    // pipeline overrides this regardless via ApplyTenantOverride, but
    // keeping the test thread aligned helps any post-visit assertions.
    $mindhearts->makeCurrent();

    $this->actingAs($user);

    $page = visit('/admin/payroll-runs/create');

    $page->assertSee('Generate payroll')
        ->assertSee('Pick an open pay period')
        ->assertSee('2026-05-MH')
        ->assertDontSee('2026-05-DEFAULT')
        ->assertNoJavaScriptErrors();

    // Visual artefact for one-off verification. Saved to
    // tests/Browser/screenshots/ — gitignore is up to the project to
    // configure if these accumulate.
    $page->screenshot(filename: 'payroll-runs-create-mindhearts');
});
