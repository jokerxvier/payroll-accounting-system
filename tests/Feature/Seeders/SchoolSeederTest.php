<?php

declare(strict_types=1);

use App\Models\Pas\School;
use Database\Seeders\SchoolSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Phase B.1 — SchoolSeeder coverage.
 *
 * Locks in the production-safe seeder contract: one default-slug row,
 * idempotent on re-run, and the seeded LMS password is encrypted at
 * rest (the same cast that production-code paths rely on).
 */

it('creates a single default school row', function (): void {
    $this->seed(SchoolSeeder::class);

    expect(School::query()->count())->toBe(1);
    expect(School::query()->where('slug', 'default')->exists())->toBeTrue();
});

it('is idempotent on re-run', function (): void {
    $this->seed(SchoolSeeder::class);

    $idAfterFirst = School::query()->where('slug', 'default')->value('id');

    expect($idAfterFirst)->not->toBeNull();

    $this->seed(SchoolSeeder::class);

    expect(School::query()->count())->toBe(1);
    expect(School::query()->where('slug', 'default')->value('id'))->toBe($idAfterFirst);
});

it('encrypts the seeded lms_db_password at rest', function (): void {
    // The seeder pulls from config('database.connections.lms.*') (post-split
    // — see SchoolSeeder for context); set a distinctive non-empty value so
    // the assertions below can detect the ciphertext / plaintext split
    // unambiguously.
    config(['database.connections.lms.password' => 'plaintext-from-config']);

    $this->seed(SchoolSeeder::class);

    $rawRow = DB::table('pas_schools')->where('slug', 'default')->first();

    expect($rawRow)->not->toBeNull();
    // The encrypted blob in the raw column must NEVER equal the
    // plaintext we configured.
    expect((string) $rawRow->lms_db_password)->not->toBe('plaintext-from-config');
    expect(strlen((string) $rawRow->lms_db_password))->toBeGreaterThan(strlen('plaintext-from-config'));

    $school = School::query()->where('slug', 'default')->firstOrFail();

    // The cast round-trips back to plaintext.
    expect($school->lms_db_password)->toBe('plaintext-from-config');
});
