<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * GET /admin/contribution-tables
 *
 * - Unauthenticated requests redirect to /login.
 * - Only super-admin may view the index; every other payroll role gets 403.
 * - Inertia page name is `admin/contribution-tables/index`; the `grouped`
 *   prop is keyed by contribution_code.
 */

function indexAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

it('redirects unauthenticated users to the login screen', function () {
    $this->get('/admin/contribution-tables')->assertRedirect('/login');
});

it('forbids the payroll-officer role on index', function () {
    $user = indexAuthAs('payroll-officer');

    $this->actingAs($user)
        ->get('/admin/contribution-tables')
        ->assertForbidden();
});

it('forbids the hr role on index', function () {
    $user = indexAuthAs('hr');

    $this->actingAs($user)
        ->get('/admin/contribution-tables')
        ->assertForbidden();
});

it('forbids the auditor role on index', function () {
    $user = indexAuthAs('auditor');

    $this->actingAs($user)
        ->get('/admin/contribution-tables')
        ->assertForbidden();
});

it('forbids the employee role on index', function () {
    $user = indexAuthAs('employee');

    $this->actingAs($user)
        ->get('/admin/contribution-tables')
        ->assertForbidden();
});

it('renders the index for super-admin and groups rows by contribution_code', function () {
    $user = indexAuthAs('super-admin');

    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => '2025-01-01',
    ]);
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2025-01-01',
        'effective_to' => null,
    ]);
    StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    $this->actingAs($user)
        ->get('/admin/contribution-tables')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/contribution-tables/index')
                ->has('grouped.'.StatutoryContribution::CODE_SSS, 2)
                ->has('grouped.'.StatutoryContribution::CODE_BIR, 1)
                ->has('codeOptions', 4)
                ->has('algorithmOptions', 4),
        );
});

it('renders an empty index when there are no rows', function () {
    $user = indexAuthAs('super-admin');

    $this->actingAs($user)
        ->get('/admin/contribution-tables')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/contribution-tables/index')
                ->where('grouped', [])
                ->has('codeOptions', 4)
                ->has('algorithmOptions', 4),
        );
});
