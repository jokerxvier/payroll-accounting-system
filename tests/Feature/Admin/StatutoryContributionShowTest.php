<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * GET /admin/contribution-tables/{contribution}
 *
 * Pinned behaviors:
 *  - Auth + role gates: only super-admin may view.
 *  - History payload: every OTHER version of the same code (DESC by
 *    effective_from), including voided rows so admins can see retired
 *    rates with their void provenance.
 *  - `can.update` and `can.void` props mirror the policy gates so the
 *    React surface can hide buttons it isn't allowed to call.
 */

function showAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('redirects unauthenticated users to login on show', function () {
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    $this->get("/admin/contribution-tables/{$row->id}")
        ->assertRedirect('/login');
});

it('forbids non-super-admin roles on show', function (string $role) {
    $user = showAuthAs($role);
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    $this->actingAs($user)
        ->get("/admin/contribution-tables/{$row->id}")
        ->assertForbidden();
})->with([
    'payroll-officer',
    'hr',
    'auditor',
    'employee',
]);

it('renders the show page with row + supersession history for super-admin', function () {
    $user = showAuthAs('super-admin');

    $oldest = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2020-01-01',
        'effective_to' => '2024-01-01',
    ]);
    $middle = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => CarbonImmutable::now()->addDays(7)->toDateString(),
    ]);
    $newest = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    // shouldExist=false skips the page-file existence check so this test
    // can ship before Task #13 lands `show.tsx` in resources/js/pages/.
    $this->actingAs($user)
        ->get("/admin/contribution-tables/{$newest->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/contribution-tables/show', false)
            ->where('contribution.id', $newest->id)
            ->has('history', 2)
            // History ordered by effective_from DESC: middle (2024) first,
            // oldest (2020) second.
            ->where('history.0.id', $middle->id)
            ->where('history.1.id', $oldest->id),
        );
});

it('includes voided versions in history with voided_at populated', function () {
    $user = showAuthAs('super-admin');

    $voided = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2022-01-01',
        'effective_to' => '2024-01-01',
        'voided_at' => CarbonImmutable::now()->subMonths(3),
        'voided_by_user_id' => $user->id,
    ]);
    $live = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    $this->actingAs($user)
        ->get("/admin/contribution-tables/{$live->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/contribution-tables/show', false)
            ->has('history', 1)
            ->where('history.0.id', $voided->id)
            ->where('history.0.voided_at', fn ($value) => $value !== null),
        );
});

it('exposes can.update=true and can.void=true on an editable row', function () {
    $user = showAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => null,
    ]);

    $this->actingAs($user)
        ->get("/admin/contribution-tables/{$row->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/contribution-tables/show', false)
            ->where('can.update', true)
            ->where('can.void', true),
        );
});

it('exposes can.update=false and can.void=false on a non-editable (past-dated) row', function () {
    $user = showAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2020-01-01',
        'effective_to' => null,
        'voided_at' => null,
    ]);

    $this->actingAs($user)
        ->get("/admin/contribution-tables/{$row->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/contribution-tables/show', false)
            ->where('can.update', false)
            ->where('can.void', false),
        );
});
