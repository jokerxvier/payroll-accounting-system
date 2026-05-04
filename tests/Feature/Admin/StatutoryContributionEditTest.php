<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * GET /admin/contribution-tables/{contribution}/edit
 *
 * Pinned behaviors:
 *  - Auth + role gates: only super-admin may reach the edit form.
 *  - Editability gate: even super-admin gets 403 on a past-dated,
 *    superseded, or voided row. The `update` policy combines role +
 *    isEditable() so the Gate short-circuits before the form renders.
 *  - Edit page payload: the row + recommendedCodes + algorithmOptions
 *    so the React form can dropdown-render the same shape as create.
 */

function editAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('redirects unauthenticated users to login on edit', function () {
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    $this->get("/admin/contribution-tables/{$row->id}/edit")
        ->assertRedirect('/login');
});

it('forbids non-super-admin roles on edit', function (string $role) {
    $user = editAuthAs($role);
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    $this->actingAs($user)
        ->get("/admin/contribution-tables/{$row->id}/edit")
        ->assertForbidden();
})->with([
    'payroll-officer',
    'hr',
    'auditor',
    'employee',
]);

it('renders the edit page on an editable row for super-admin', function () {
    $user = editAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => null,
    ]);

    // shouldExist=false skips the page-file existence check so this test
    // can ship before Task #13 lands `edit.tsx` in resources/js/pages/.
    $this->actingAs($user)
        ->get("/admin/contribution-tables/{$row->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/contribution-tables/edit', false)
            ->where('contribution.id', $row->id)
            ->has('recommendedCodes')
            ->has('algorithmOptions'),
        );
});

it('forbids edit on a past-dated row even for super-admin', function () {
    $user = editAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2020-01-01',
        'effective_to' => null,
        'voided_at' => null,
    ]);

    $this->actingAs($user)
        ->get("/admin/contribution-tables/{$row->id}/edit")
        ->assertForbidden();
});

it('forbids edit on a superseded row even for super-admin', function () {
    $user = editAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => CarbonImmutable::now()->addYears(1)->toDateString(),
        'voided_at' => null,
    ]);

    $this->actingAs($user)
        ->get("/admin/contribution-tables/{$row->id}/edit")
        ->assertForbidden();
});

it('forbids edit on a voided row even for super-admin', function () {
    $user = editAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => CarbonImmutable::now(),
    ]);

    $this->actingAs($user)
        ->get("/admin/contribution-tables/{$row->id}/edit")
        ->assertForbidden();
});
