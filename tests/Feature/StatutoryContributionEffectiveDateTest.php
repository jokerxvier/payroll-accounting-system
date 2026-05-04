<?php

declare(strict_types=1);

use App\Models\Pas\AuditLog;
use App\Models\Pas\StatutoryContribution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The unified pas_statutory_contributions table holds versioned, effective-dated
 * rule sets for any contribution type. These tests pin the boundary semantics:
 *
 *   - effective_to is EXCLUSIVE: a row with effective_to=2024-12-31 is NOT
 *     active on 2024-12-31; the 2025-01-01 supersession is.
 *   - scopeForDate filters by date AND optionally by contribution_code.
 *   - rules are stored / retrieved as a nested array.
 *   - Auditable fires on create.
 */

it('selects the active row by date for a given contribution code', function () {
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => '2024-12-31',
    ]);
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2025-01-01',
        'effective_to' => null,
    ]);

    $code = StatutoryContribution::CODE_SSS;

    expect(StatutoryContribution::query()->forDate(CarbonImmutable::parse('2023-12-31'), $code)->count())->toBe(0)
        ->and(StatutoryContribution::query()->forDate(CarbonImmutable::parse('2024-06-15'), $code)->count())->toBe(1)
        ->and(StatutoryContribution::query()->forDate(CarbonImmutable::parse('2025-01-01'), $code)->count())->toBe(1)
        ->and(StatutoryContribution::query()->forDate(CarbonImmutable::parse('2025-06-15'), $code)->count())->toBe(1);
});

it('treats effective_to as exclusive at the supersession boundary', function () {
    // The day-of-supersession invariant: outgoing.effective_to == incoming.effective_from.
    // Querying ON that day returns ONLY the incoming row.
    $outgoing = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => '2025-01-01',
    ]);
    $incoming = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2025-01-01',
        'effective_to' => null,
    ]);

    $rows = StatutoryContribution::query()
        ->forDate(CarbonImmutable::parse('2025-01-01'), StatutoryContribution::CODE_SSS)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($incoming->id)
        ->and($rows->first()->id)->not->toBe($outgoing->id);
});

it('returns the active row via current() helper or null when none match', function () {
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2025-01-01',
        'effective_to' => null,
    ]);

    expect(
        StatutoryContribution::current(StatutoryContribution::CODE_SSS, CarbonImmutable::parse('2025-06-15')),
    )->not->toBeNull();

    expect(
        StatutoryContribution::current('NONEXISTENT', CarbonImmutable::parse('2025-06-15')),
    )->toBeNull();

    expect(
        StatutoryContribution::current(StatutoryContribution::CODE_SSS, CarbonImmutable::parse('2023-12-31')),
    )->toBeNull();
});

it('isolates rows by contribution_code', function () {
    StatutoryContribution::factory()->bir()->create(['effective_from' => '2024-01-01']);
    StatutoryContribution::factory()->sss()->create(['effective_from' => '2024-01-01']);
    StatutoryContribution::factory()->philhealth()->create(['effective_from' => '2024-01-01']);

    $date = CarbonImmutable::parse('2024-06-15');

    expect(StatutoryContribution::query()->forDate($date, StatutoryContribution::CODE_SSS)->count())->toBe(1)
        ->and(StatutoryContribution::query()->forDate($date, StatutoryContribution::CODE_BIR)->count())->toBe(1)
        ->and(StatutoryContribution::query()->forDate($date, StatutoryContribution::CODE_PHILHEALTH)->count())->toBe(1)
        ->and(StatutoryContribution::query()->forDate($date)->count())->toBe(3);
});

it('round-trips the rules JSON as a nested array', function () {
    $row = StatutoryContribution::factory()->bir()->create();

    $reloaded = StatutoryContribution::query()->findOrFail($row->id);

    expect($reloaded->rules)->toBeArray()
        ->and($reloaded->rules)->toHaveKey('period_types')
        ->and($reloaded->rules['period_types'])->toHaveKey('monthly')
        ->and($reloaded->rules['period_types']['monthly'])->toBeArray()
        ->and($reloaded->rules['period_types']['monthly'][0])->toHaveKey('excess_rate_bp');
});

it('writes one audit log row when a StatutoryContribution is created', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $row = StatutoryContribution::factory()->philhealth()->create();

    $audits = AuditLog::query()
        ->where('auditable_type', StatutoryContribution::class)
        ->where('auditable_id', $row->id)
        ->where('action', 'created')
        ->get();

    expect($audits)->toHaveCount(1)
        ->and($audits->first()->actor_id)->toBe($user->id);
});

it('skips voided rows in current() and forDate()', function () {
    // A row that would otherwise be active (open-ended, well within range)
    // but stamped as voided. current() and forDate() must both miss it.
    StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2024-01-01',
        'effective_to' => null,
        'voided_at' => CarbonImmutable::now(),
    ]);

    expect(StatutoryContribution::current(StatutoryContribution::CODE_SSS, CarbonImmutable::parse('2024-06-15')))
        ->toBeNull();

    expect(StatutoryContribution::query()
        ->forDate(CarbonImmutable::parse('2024-06-15'), StatutoryContribution::CODE_SSS)
        ->count())->toBe(0);
});
