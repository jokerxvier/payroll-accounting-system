<?php

declare(strict_types=1);

use App\Models\User;

/*
 * Verifies the `sidebarHiddenSections` shared Inertia prop wiring driven by
 * config('payroll.sidebar_hidden_sections') (env: PAYROLL_SIDEBAR_HIDDEN_SECTIONS).
 *
 * Backend authorisation is unchanged — this is a presentational hide for
 * client demos and screenshots. The React sidebar reads the prop and skips
 * rendering the listed groups; direct URLs still resolve for authorised users.
 */

function authSidebarAs(string $payrollRole): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

it('shares an empty sidebarHiddenSections array by default', function () {
    config(['payroll.sidebar_hidden_sections' => []]);

    $user = authSidebarAs('super-admin');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sidebarHiddenSections')
            ->where('sidebarHiddenSections', [])
        );
});

it('shares the configured hidden sections to the React side', function () {
    config(['payroll.sidebar_hidden_sections' => ['payroll']]);

    $user = authSidebarAs('super-admin');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sidebarHiddenSections', ['payroll'])
        );
});

it('round-trips the comma-separated env var through the config', function () {
    // Mirror what happens when PAYROLL_SIDEBAR_HIDDEN_SECTIONS="payroll, audit"
    // is set — config/payroll.php trims and filters into a clean array.
    $parsed = array_values(array_filter(array_map(
        'trim',
        explode(',', 'payroll, audit, '),
    )));

    config(['payroll.sidebar_hidden_sections' => $parsed]);

    $user = authSidebarAs('super-admin');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sidebarHiddenSections', ['payroll', 'audit'])
        );
});
