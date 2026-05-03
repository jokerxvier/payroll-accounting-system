<?php

declare(strict_types=1);

/*
 * Browser smoke test for the Employee Show page → Deductions card flow
 * (Week 7, Chunk 6). Currently SKIPPED — Pest 4 ships browser-test support
 * via `pestphp/pest-plugin-laravel` ^4.1, but the project has not yet
 * installed Playwright nor wired the necessary `browser()` test helper
 * configuration. Setting that up is its own work item.
 *
 * Investigation summary (2026-05-03):
 *   - composer.json carries pestphp/pest ^4.6 and pest-plugin-laravel ^4.1.
 *   - There is NO `playwright.config.{ts,js}` and NO `@playwright/test` in
 *     the npm dependency tree.
 *   - There is no existing `tests/Browser/` test to copy from — this is
 *     the first one in the suite.
 *   - The Pest plugin's `visit()` / `browser()` helpers require a running
 *     Playwright driver to actually exercise the DOM.
 *
 * When the browser-test infrastructure lands (separate task), remove the
 * `->skip(…)` modifier and uncomment the body. The Inertia partial reload
 * triggered by `preserveScroll: true` already returns the new deduction row
 * in the same response, so no extra await/poll loop is needed.
 *
 * Acceptance pin (per `rules/PLAN.md` Section 5 Phase 2 chunk 6 row):
 *   "One Pest 4 browser test (`tests/Browser/EmployeeShowDeductionsCardTest.php`) —
 *    open sheet, fill, submit, see new row."
 */

it('hr can add a deduction via the Show page sheet and see the row appear', function () {
    // === When the browser harness is wired, the body is roughly:
    //
    // use App\Models\Lms\Staff;
    // use App\Models\Pas\DeductionType;
    // use App\Models\Pas\EmployeeProfile;
    // use App\Models\User;
    //
    // config([
    //     'payroll.employee_role_allowlist' => [1, 4, 5],
    //     'payroll.lms_role_to_payroll_role' => [],
    // ]);
    //
    // $user = User::factory()->create();
    // $user->syncRoles(['hr']);
    //
    // $staff = Staff::query()->whereIn('role_id', [1, 4, 5])->first();
    // EmployeeProfile::query()->create([
    //     'lms_staff_id' => (int) $staff->id,
    //     'employment_classification' => 'regular',
    //     'pay_frequency' => 'monthly',
    //     'basic_salary_centavos' => 5_000_000,
    //     'is_active' => true,
    // ]);
    // DeductionType::factory()->create([
    //     'code' => 'HMO',
    //     'name' => 'HMO premium',
    //     'calc_method' => DeductionType::CALC_FIXED,
    //     'source' => 'employee',
    //     'is_active' => true,
    // ]);
    //
    // $page = visit("/employees/{$staff->id}")->actingAs($user);
    // $page->click('Add deduction')
    //      ->select('Deduction type', 'HMO premium')
    //      ->fill('Amount', '500.00')
    //      ->click('Add deduction')                  // submit button text
    //      ->assertSee('HMO premium')                // row in the card
    //      ->assertSee('₱500.00');
})->skip(
    'Pest 4 browser test infrastructure not yet configured. Install Playwright and wire the browser driver, then unskip and uncomment the body. Tracked separately from the Week 7 deliverables.'
);
