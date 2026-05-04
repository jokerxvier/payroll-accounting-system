<?php

declare(strict_types=1);

use App\Models\Pas\StatutoryContribution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Pins the edit-eligibility contract for `pas_statutory_contributions`.
 *
 * `isEditable()` is the single source of truth that the policy and any
 * future admin surface (edit / void) consult before mutating a row. The
 * cases below cover every disqualifier:
 *
 *   - effective_from is today or earlier      → false (live or historical)
 *   - effective_to is set                     → false (superseded)
 *   - voided_at is set                        → false (retired)
 *
 * The only true case is the strict intersection: future + open-ended +
 * not-voided. Any of those flips and the row becomes immutable.
 */

it('returns true for a future-dated open-ended non-voided row', function () {
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => null,
    ]);

    expect($row->isEditable())->toBeTrue();
});

it('returns false when effective_from is today', function () {
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->startOfDay()->toDateString(),
        'effective_to' => null,
        'voided_at' => null,
    ]);

    expect($row->isEditable())->toBeFalse();
});

it('returns false when effective_from is in the past', function () {
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => '2020-01-01',
        'effective_to' => null,
        'voided_at' => null,
    ]);

    expect($row->isEditable())->toBeFalse();
});

it('returns false when superseded (effective_to set)', function () {
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => CarbonImmutable::now()->addYears(1)->toDateString(),
        'voided_at' => null,
    ]);

    expect($row->isEditable())->toBeFalse();
});

it('returns false when voided', function () {
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => CarbonImmutable::now(),
    ]);

    expect($row->isEditable())->toBeFalse();
});

it('void() stamps voided_at, voided_by_user_id, and disqualifies isEditable()', function () {
    $user = User::factory()->create();
    $row = StatutoryContribution::factory()->sss()->create([
        'effective_from' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'effective_to' => null,
        'voided_at' => null,
    ]);

    expect($row->isEditable())->toBeTrue();

    $row->void($user->id);
    $row->refresh();

    expect($row->isVoided())->toBeTrue()
        ->and($row->voided_at)->not->toBeNull()
        ->and($row->voided_by_user_id)->toBe($user->id)
        ->and($row->voidedBy)->not->toBeNull()
        ->and($row->voidedBy->id)->toBe($user->id)
        ->and($row->isEditable())->toBeFalse();
});

it('isVoided() reflects the voided_at column directly', function () {
    $live = StatutoryContribution::factory()->sss()->create(['voided_at' => null]);
    $dead = StatutoryContribution::factory()->sss()->create(['voided_at' => CarbonImmutable::now()]);

    expect($live->isVoided())->toBeFalse()
        ->and($dead->isVoided())->toBeTrue();
});
