<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * PATCH /admin/contribution-tables/{contribution}
 *
 * Pinned behaviors:
 *  - Auth + role gates: only super-admin may PATCH.
 *  - Editability gate: 403 on past-dated, superseded, or voided rows. The
 *    FormRequest's authorize() calls the policy so non-editable rows
 *    short-circuit BEFORE validation runs.
 *  - contribution_code is immutable: any submitted value other than the
 *    route-model's existing code surfaces as a 422 on `contribution_code`,
 *    with the row unchanged on disk.
 *  - rules JSON shape is re-validated by the algorithm strategy on every
 *    PATCH (same shape contract as store).
 *  - Self-exclusion in chronology check: PATCHing a row with its own
 *    existing effective_from MUST succeed (the row doesn't compete with
 *    itself for "latest version").
 */

function updateAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

/**
 * Build a valid SSS PATCH payload that mirrors the factory shape so the
 * happy-path test exercises a genuine no-op-plus-rate-tweak save.
 *
 * @return array<string, mixed>
 */
function validSssPatchPayload(string $effectiveFrom, int $employeeShareCentavos = 25_000): array
{
    return [
        'contribution_code' => StatutoryContribution::CODE_SSS,
        'label' => 'SSS contribution (revised)',
        'algorithm' => StatutoryContribution::ALGORITHM_SALARY_BAND,
        'application_order' => 10,
        'applies_to' => StatutoryContribution::APPLIES_TO_GROSS_PAY,
        'effective_from' => $effectiveFrom,
        'rules' => [
            'bands' => [
                [
                    'lower' => 0,
                    'upper' => 525_000,
                    'contribution_base' => 500_000,
                    'employee_share' => $employeeShareCentavos,
                    'employer_share' => 47_500,
                    'employer_aux_share' => 1_000,
                    'employee_aux_share' => 0,
                ],
                [
                    'lower' => 525_000,
                    'upper' => null,
                    'contribution_base' => 1_500_000,
                    'employee_share' => 67_500,
                    'employer_share' => 142_500,
                    'employer_aux_share' => 1_000,
                    'employee_aux_share' => 0,
                ],
            ],
        ],
        'notes' => null,
    ];
}

it('redirects unauthenticated users to login on update', function () {
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    $this->patch("/admin/contribution-tables/{$row->id}", validSssPatchPayload($row->effective_from->toDateString()))
        ->assertRedirect('/login');
});

it('allows payroll-officer and hr to update an editable row', function (string $role) {
    $user = updateAuthAs($role);
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    $this->actingAs($user)
        ->patch("/admin/contribution-tables/{$row->id}", validSssPatchPayload($row->effective_from->toDateString()))
        ->assertRedirect("/admin/contribution-tables/{$row->id}");
})->with([
    'payroll-officer',
    'hr',
]);

it('forbids update for auditor and employee', function (string $role) {
    $user = updateAuthAs($role);
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
    ]);

    $this->actingAs($user)
        ->patch("/admin/contribution-tables/{$row->id}", validSssPatchPayload($row->effective_from->toDateString()))
        ->assertForbidden();
})->with([
    'auditor',
    'employee',
]);

it('forbids update on a past-dated row even for super-admin', function () {
    $user = updateAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2020-01-01',
        'effective_to' => null,
        'voided_at' => null,
    ]);

    $this->actingAs($user)
        ->patch("/admin/contribution-tables/{$row->id}", validSssPatchPayload('2020-01-01'))
        ->assertForbidden();
});

it('forbids update on a voided row even for super-admin', function () {
    $user = updateAuthAs('super-admin');
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => CarbonImmutable::now(),
    ]);

    $this->actingAs($user)
        ->patch("/admin/contribution-tables/{$row->id}", validSssPatchPayload($row->effective_from->toDateString()))
        ->assertForbidden();
});

it('persists a rate change on a future-dated editable row and redirects to show', function () {
    $user = updateAuthAs('super-admin');
    $effectiveFrom = CarbonImmutable::now()->addDays(7)->toDateString();
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => $effectiveFrom,
        'effective_to' => null,
        'voided_at' => null,
    ]);

    expect($row->rules['bands'][0]['employee_share'])->toBe(22_500);

    $this->actingAs($user)
        ->from("/admin/contribution-tables/{$row->id}/edit")
        ->patch(
            "/admin/contribution-tables/{$row->id}",
            validSssPatchPayload($effectiveFrom, employeeShareCentavos: 25_000),
        )
        ->assertRedirect("/admin/contribution-tables/{$row->id}");

    $row->refresh();

    expect($row->rules['bands'][0]['employee_share'])->toBe(25_000)
        ->and($row->label)->toBe('SSS contribution (revised)');
});

it('rejects an attempt to change contribution_code', function () {
    $user = updateAuthAs('super-admin');
    $effectiveFrom = CarbonImmutable::now()->addDays(7)->toDateString();
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => $effectiveFrom,
        'effective_to' => null,
    ]);

    $payload = validSssPatchPayload($effectiveFrom);
    $payload['contribution_code'] = 'PHILHEALTH';

    $this->actingAs($user)
        ->from("/admin/contribution-tables/{$row->id}/edit")
        ->patch("/admin/contribution-tables/{$row->id}", $payload)
        ->assertSessionHasErrors('contribution_code');

    $row->refresh();

    expect($row->contribution_code)->toBe(StatutoryContribution::CODE_SSS);
});

it('rejects malformed rules JSON for the chosen algorithm', function () {
    $user = updateAuthAs('super-admin');
    $effectiveFrom = CarbonImmutable::now()->addDays(7)->toDateString();
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => $effectiveFrom,
        'effective_to' => null,
    ]);

    $payload = validSssPatchPayload($effectiveFrom);
    $payload['rules'] = ['bands' => []];

    $this->actingAs($user)
        ->from("/admin/contribution-tables/{$row->id}/edit")
        ->patch("/admin/contribution-tables/{$row->id}", $payload)
        ->assertSessionHasErrors('rules');
});

it('allows a no-op resave at the same effective_from (chronology check excludes self)', function () {
    $user = updateAuthAs('super-admin');
    $effectiveFrom = CarbonImmutable::now()->addDays(7)->toDateString();
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => $effectiveFrom,
        'effective_to' => null,
        'voided_at' => null,
    ]);

    $this->actingAs($user)
        ->from("/admin/contribution-tables/{$row->id}/edit")
        ->patch("/admin/contribution-tables/{$row->id}", validSssPatchPayload($effectiveFrom))
        ->assertSessionHasNoErrors()
        ->assertRedirect("/admin/contribution-tables/{$row->id}");
});
