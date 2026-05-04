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
                ->has('recommendedCodes', 5)
                ->has('algorithmOptions', 5)
                ->where('can.modify', true),
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
                ->has('recommendedCodes', 5)
                ->has('algorithmOptions', 5)
                ->where('can.modify', true),
        );
});

it('decorates each grouped row with an is_editable boolean', function () {
    $user = indexAuthAs('super-admin');

    // Future-dated open-ended row → editable.
    $future = StatutoryContribution::factory()->sss()->create([
        'effective_from' => now()->addYear()->toDateString(),
        'effective_to' => null,
    ]);

    // Past-dated, currently-active row (open-ended) → not editable.
    $past = StatutoryContribution::factory()->bir()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
    ]);

    // Voided future-dated row → not editable.
    $voided = StatutoryContribution::factory()->bir()->create([
        'contribution_code' => StatutoryContribution::CODE_PAGIBIG,
        'effective_from' => now()->addYear()->toDateString(),
        'effective_to' => null,
    ]);
    $voided->void($user->id);

    $this->actingAs($user)
        ->get('/admin/contribution-tables')
        ->assertOk()
        ->assertInertia(function ($page) use ($future, $past, $voided) {
            $page->component('admin/contribution-tables/index');

            $sssRows = collect($page->toArray()['props']['grouped'][StatutoryContribution::CODE_SSS]);
            $birRows = collect($page->toArray()['props']['grouped'][StatutoryContribution::CODE_BIR]);
            $pagRows = collect($page->toArray()['props']['grouped'][StatutoryContribution::CODE_PAGIBIG]);

            expect($sssRows->firstWhere('id', $future->id)['is_editable'])->toBeTrue();
            expect($birRows->firstWhere('id', $past->id)['is_editable'])->toBeFalse();
            expect($pagRows->firstWhere('id', $voided->id)['is_editable'])->toBeFalse();
        });
});

it('exposes can.modify=false for forbidden roles', function () {
    // The forbidden roles already 403 on the index, so they never see `can`.
    // Sanity-check that the prop resolves to false for any user that lacks
    // the super-admin role by hitting the controller's allow check directly
    // through Gate. Since the request itself is forbidden upstream, the
    // assertion below is on the gate not the response.
    foreach (['payroll-officer', 'hr', 'auditor', 'employee'] as $roleName) {
        $user = indexAuthAs($roleName);
        expect($user->can('create', StatutoryContribution::class))->toBeFalse();
    }
});
