<?php

declare(strict_types=1);

use App\Exports\StatutoryContributionExport;
use App\Models\Pas\StatutoryContribution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

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

it('allows the payroll-officer role on index (read-only)', function () {
    $user = indexAuthAs('payroll-officer');

    $this->actingAs($user)
        ->get('/admin/contribution-tables')
        ->assertOk();
});

it('allows the hr role on index (read-only)', function () {
    $user = indexAuthAs('hr');

    $this->actingAs($user)
        ->get('/admin/contribution-tables')
        ->assertOk();
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
    // The forbidden roles (auditor, employee) 403 on the index, so they never
    // see `can`. Sanity-check that `create` returns false for them via Gate.
    // payroll-officer + hr now manage the catalog (see policy) so they're
    // intentionally excluded from this matrix.
    foreach (['auditor', 'employee'] as $roleName) {
        $user = indexAuthAs($roleName);
        expect($user->can('create', StatutoryContribution::class))->toBeFalse();
    }
});

/*
 * Phase 3 W12 Stage B — GET /admin/contribution-tables/template
 *
 * Excel snapshot of every row. Audit / archival surface; not a round-trip
 * import template. Same auth matrix as the index.
 */

it('super-admin downloads the contribution-tables Excel snapshot', function () {
    Excel::fake();
    $user = indexAuthAs('super-admin');
    StatutoryContribution::factory()->sss()->create();

    $this->actingAs($user)
        ->get('/admin/contribution-tables/template')
        ->assertOk();

    Excel::assertDownloaded(
        'contribution-tables.xlsx',
        fn ($export): bool => $export instanceof StatutoryContributionExport,
    );
});

it('allows payroll-officer and hr to download the Excel snapshot', function (string $role) {
    $user = indexAuthAs($role);

    $this->actingAs($user)
        ->get('/admin/contribution-tables/template')
        ->assertOk();
})->with(['payroll-officer', 'hr']);

it('forbids the contribution-tables Excel snapshot for auditor and employee', function (string $role) {
    $user = indexAuthAs($role);

    $this->actingAs($user)
        ->get('/admin/contribution-tables/template')
        ->assertForbidden();
})->with(['auditor', 'employee']);
