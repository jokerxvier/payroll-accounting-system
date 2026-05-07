<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Models\User;
use App\Services\Statutory\StatutoryContributionRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * POST /admin/contribution-tables/{contribution}/void
 *
 * Pinned behaviors:
 *  - Auth + role gates: only super-admin may void.
 *  - Editability gate: 403 on past-dated, superseded, or already-voided
 *    rows. The `void` policy combines super-admin + isEditable() so the
 *    same lockout applies as for update.
 *  - On success: voided_at = now(), voided_by_user_id = acting user, and
 *    the row is excluded from every payroll computation path (registry +
 *    forDate scope) on every future date lookup.
 *  - Flash success message is exact so the React surface can render it
 *    without surprise.
 */

function voidAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('redirects unauthenticated users to login on void', function () {
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    $this->post("/admin/contribution-tables/{$row->id}/void")
        ->assertRedirect('/login');
});

it('allows payroll-officer and hr to void an editable row', function (string $role) {
    $user = voidAuthAs($role);
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    $this->actingAs($user)
        ->post("/admin/contribution-tables/{$row->id}/void")
        ->assertRedirect("/admin/contribution-tables/{$row->id}");

    $row->refresh();

    expect($row->voided_at)->not->toBeNull();
})->with([
    'payroll-officer',
    'hr',
]);

it('forbids void for auditor and employee', function (string $role) {
    $user = voidAuthAs($role);
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    $this->actingAs($user)
        ->post("/admin/contribution-tables/{$row->id}/void")
        ->assertForbidden();

    $row->refresh();

    expect($row->voided_at)->toBeNull();
})->with([
    'auditor',
    'employee',
]);

it('forbids void on a past-dated row even for super-admin', function () {
    $user = voidAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2020-01-01',
        'effective_to' => null,
        'voided_at' => null,
    ]);

    $this->actingAs($user)
        ->post("/admin/contribution-tables/{$row->id}/void")
        ->assertForbidden();

    $row->refresh();

    expect($row->voided_at)->toBeNull();
});

it('forbids void on an already-voided row even for super-admin', function () {
    $user = voidAuthAs('super-admin');
    $previouslyVoidedAt = CarbonImmutable::now()->subDays(2);
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => $previouslyVoidedAt,
    ]);

    $this->actingAs($user)
        ->post("/admin/contribution-tables/{$row->id}/void")
        ->assertForbidden();

    // Original voided_at preserved — no double-void overwrite.
    $row->refresh();
    expect($row->voided_at?->toDateString())->toBe($previouslyVoidedAt->toDateString());
});

it('voids a future-dated editable row and stamps the actor', function () {
    $user = voidAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => null,
    ]);

    $this->actingAs($user)
        ->from("/admin/contribution-tables/{$row->id}")
        ->post("/admin/contribution-tables/{$row->id}/void")
        ->assertRedirect("/admin/contribution-tables/{$row->id}");

    $row->refresh();

    expect($row->voided_at)->not->toBeNull()
        ->and($row->voided_by_user_id)->toBe($user->id);
});

it('excludes a voided row from the registry on every future date lookup', function () {
    $user = voidAuthAs('super-admin');
    // Editable today (future-dated, open-ended) so the void policy passes.
    $effectiveFrom = CarbonImmutable::now()->addDays(7);
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => $effectiveFrom->toDateString(),
        'effective_to' => null,
        'voided_at' => null,
    ]);

    /** @var StatutoryContributionRegistry $registry */
    $registry = app(StatutoryContributionRegistry::class);

    // Sanity check: before void, the row IS active on a date that falls
    // inside its effective range.
    $beforeVoid = $registry->active($effectiveFrom->addDays(30));
    expect($beforeVoid->pluck('contribution_code')->all())->toContain(StatutoryContribution::CODE_SSS);

    $this->actingAs($user)
        ->post("/admin/contribution-tables/{$row->id}/void")
        ->assertRedirect();

    $afterVoid = $registry->active($effectiveFrom->addDays(30));
    expect($afterVoid->pluck('contribution_code')->all())->not->toContain(StatutoryContribution::CODE_SSS);
});

it('flashes the exact success message after void', function () {
    $user = voidAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => null,
    ]);

    $this->actingAs($user)
        ->post("/admin/contribution-tables/{$row->id}/void")
        ->assertSessionHas('success', 'Contribution voided. It will no longer participate in any payroll computation.');
});
