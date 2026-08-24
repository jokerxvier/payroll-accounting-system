<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * /admin/accounting-periods (Phase 5 Slice 1).
 *
 * Pinned behaviours:
 *  - Non-overlap. Two periods covering the same date would make
 *    AccountingPeriod::covering() ambiguous, so Slice 2's posting guard
 *    could not tell which period an entry belongs to — and closing one
 *    would only half-freeze the ledger.
 *  - close / reopen are separate POST transitions on the narrower
 *    CLOSE_PERIOD role list, each stamping its actor.
 *  - A closed period cannot be edited; its boundaries are what every entry
 *    inside it was filed against.
 *  - Periods are never deletable — no destroy route is registered.
 */

function periodAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/** @return array<string, mixed> */
function validPeriodPayload(array $overrides = []): array
{
    return array_merge([
        'code' => '2026-09',
        'name' => 'September 2026',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'fiscal_year' => 2026,
    ], $overrides);
}

it('lets every manage role view the index', function (string $role) {
    AccountingPeriod::factory()->create();

    $this->actingAs(periodAuthAs($role))
        ->get('/admin/accounting-periods')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/accounting/periods/index', false)
                ->has('periods', 1),
        );
})->with(['super-admin', 'accountant', 'payroll-officer']);

it('locks an ordinary employee out', function () {
    $this->actingAs(periodAuthAs('employee'))
        ->get('/admin/accounting-periods')
        ->assertForbidden();
});

it('requires authentication', function () {
    $this->get('/admin/accounting-periods')->assertRedirect('/login');
});

it('creates a period in the open state', function () {
    $this->actingAs(periodAuthAs('accountant'))
        ->post('/admin/accounting-periods', validPeriodPayload())
        ->assertRedirect('/admin/accounting-periods');

    $period = AccountingPeriod::query()->where('code', '2026-09')->firstOrFail();

    expect($period->status)->toBe(AccountingPeriod::STATUS_OPEN)
        ->and($period->fiscal_year)->toBe(2026)
        ->and($period->closed_at)->toBeNull();
});

it('never accepts a status from the client', function () {
    // A period is always born open; closing happens only through the
    // dedicated endpoint, which stamps the actor.
    $this->actingAs(periodAuthAs('accountant'))
        ->post('/admin/accounting-periods', validPeriodPayload([
            'status' => AccountingPeriod::STATUS_CLOSED,
        ]))
        ->assertRedirect('/admin/accounting-periods');

    expect(AccountingPeriod::query()->where('code', '2026-09')->value('status'))
        ->toBe(AccountingPeriod::STATUS_OPEN);
});

it('derives the fiscal year from the start date when omitted', function () {
    $this->actingAs(periodAuthAs('accountant'))
        ->post('/admin/accounting-periods', collect(validPeriodPayload())
            ->except('fiscal_year')
            ->all())
        ->assertSessionHasNoErrors();

    expect(AccountingPeriod::query()->where('code', '2026-09')->value('fiscal_year'))
        ->toBe(2026);
});

it('rejects an end date before the start date', function () {
    $this->actingAs(periodAuthAs('accountant'))
        ->post('/admin/accounting-periods', validPeriodPayload([
            'start_date' => '2026-09-30',
            'end_date' => '2026-09-01',
        ]))
        ->assertSessionHasErrors('end_date');
});

it('rejects a period overlapping an existing one', function (string $start, string $end) {
    AccountingPeriod::factory()->create([
        'code' => '2026-09',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
    ]);

    $this->actingAs(periodAuthAs('accountant'))
        ->post('/admin/accounting-periods', validPeriodPayload([
            'code' => '2026-09-B',
            'start_date' => $start,
            'end_date' => $end,
        ]))
        ->assertSessionHasErrors('start_date');

    expect(AccountingPeriod::query()->count())->toBe(1);
})->with([
    'identical range' => ['2026-09-01', '2026-09-30'],
    'starts inside' => ['2026-09-15', '2026-10-15'],
    'ends inside' => ['2026-08-15', '2026-09-15'],
    'fully contains' => ['2026-08-01', '2026-10-31'],
    'fully inside' => ['2026-09-10', '2026-09-20'],
]);

it('accepts an adjacent, non-overlapping period', function () {
    AccountingPeriod::factory()->create([
        'code' => '2026-09',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
    ]);

    $this->actingAs(periodAuthAs('accountant'))
        ->post('/admin/accounting-periods', validPeriodPayload([
            'code' => '2026-10',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]))
        ->assertSessionHasNoErrors();

    expect(AccountingPeriod::query()->count())->toBe(2);
});

it('does not count the period being edited as an overlap with itself', function () {
    $period = AccountingPeriod::factory()->create([
        'code' => '2026-09',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
    ]);

    $this->actingAs(periodAuthAs('accountant'))
        ->patch("/admin/accounting-periods/{$period->getKey()}", validPeriodPayload([
            'code' => '2026-09',
            'name' => 'Sept 2026 (renamed)',
        ]))
        ->assertSessionHasNoErrors();

    expect($period->fresh()->name)->toBe('Sept 2026 (renamed)');
});

it('closes an open period and stamps the actor', function () {
    $period = AccountingPeriod::factory()->create();
    $actor = periodAuthAs('accountant');

    $this->actingAs($actor)
        ->post("/admin/accounting-periods/{$period->getKey()}/close")
        ->assertRedirect('/admin/accounting-periods')
        ->assertSessionHas('success');

    $period->refresh();

    expect($period->status)->toBe(AccountingPeriod::STATUS_CLOSED)
        ->and($period->closed_at)->not()->toBeNull()
        ->and($period->closed_by_user_id)->toBe($actor->getKey())
        ->and($period->isOpen())->toBeFalse();
});

it('refuses to close an already-closed period', function () {
    $period = AccountingPeriod::factory()->closed()->create();

    $this->actingAs(periodAuthAs('accountant'))
        ->post("/admin/accounting-periods/{$period->getKey()}/close")
        ->assertForbidden();
});

it('reopens a closed period and stamps the actor separately', function () {
    $closer = periodAuthAs('accountant');
    $period = AccountingPeriod::factory()->closed()->create([
        'closed_by_user_id' => $closer->getKey(),
    ]);

    $reopener = periodAuthAs('super-admin');

    $this->actingAs($reopener)
        ->post("/admin/accounting-periods/{$period->getKey()}/reopen")
        ->assertRedirect('/admin/accounting-periods')
        ->assertSessionHas('success');

    $period->refresh();

    expect($period->status)->toBe(AccountingPeriod::STATUS_OPEN)
        ->and($period->reopened_at)->not()->toBeNull()
        ->and($period->reopened_by_user_id)->toBe($reopener->getKey())
        // The close stamps survive — they record that the period WAS closed
        // and by whom, which is exactly the history an auditor needs.
        ->and($period->closed_by_user_id)->toBe($closer->getKey())
        ->and($period->closed_at)->not()->toBeNull();
});

it('refuses to reopen an already-open period', function () {
    $period = AccountingPeriod::factory()->create();

    $this->actingAs(periodAuthAs('accountant'))
        ->post("/admin/accounting-periods/{$period->getKey()}/reopen")
        ->assertForbidden();
});

it('keeps close and reopen away from the broader manage roles', function (string $role) {
    // payroll-officer can manage the chart and tax rates, but freezing and
    // un-freezing the ledger is deliberately narrower.
    $open = AccountingPeriod::factory()->create(['code' => '2026-11']);

    $this->actingAs(periodAuthAs($role))
        ->post("/admin/accounting-periods/{$open->getKey()}/close")
        ->assertForbidden();

    expect($open->fresh()->status)->toBe(AccountingPeriod::STATUS_OPEN);
})->with(['payroll-officer', 'hr', 'auditor']);

it('refuses to edit a closed period', function () {
    $period = AccountingPeriod::factory()->closed()->create([
        'code' => '2026-09',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
    ]);

    $this->actingAs(periodAuthAs('accountant'))
        ->patch("/admin/accounting-periods/{$period->getKey()}", validPeriodPayload([
            'code' => '2026-09',
            'name' => 'Should not apply',
        ]))
        ->assertForbidden();

    expect($period->fresh()->name)->not()->toBe('Should not apply');
});

it('registers no delete route for periods', function () {
    $period = AccountingPeriod::factory()->create();

    // Slice 2 attaches journal entries to periods; deleting one would orphan
    // the ledger's filing system, so no DELETE verb is registered. The URI
    // itself exists (PATCH updates it), so the router answers 405 Method Not
    // Allowed rather than 404.
    $this->actingAs(periodAuthAs('super-admin'))
        ->delete("/admin/accounting-periods/{$period->getKey()}")
        ->assertMethodNotAllowed();

    expect(AccountingPeriod::query()->whereKey($period->getKey())->exists())->toBeTrue();
});

it('finds the period covering a given date', function () {
    AccountingPeriod::factory()->create([
        'code' => '2026-09',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
    ]);

    $match = AccountingPeriod::query()
        ->covering(CarbonImmutable::parse('2026-09-15'))
        ->first();

    expect($match?->code)->toBe('2026-09');

    // Boundaries are inclusive at both ends.
    expect(AccountingPeriod::query()->covering(CarbonImmutable::parse('2026-09-01'))->exists())->toBeTrue();
    expect(AccountingPeriod::query()->covering(CarbonImmutable::parse('2026-09-30'))->exists())->toBeTrue();
    expect(AccountingPeriod::query()->covering(CarbonImmutable::parse('2026-10-01'))->exists())->toBeFalse();
});
