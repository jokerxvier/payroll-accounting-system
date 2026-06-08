<?php

declare(strict_types=1);

use App\Models\Pas\School;
use Illuminate\Support\Facades\DB;

/*
 * Phase B.1 — verify the `encrypted` cast on School::lms_db_password
 * round-trips plaintext while persisting an encrypted blob at rest.
 * The cast is driver-agnostic; sqlite is fine here.
 */

it('encrypts lms_db_password at rest and decrypts it on read', function (): void {
    $school = School::factory()->create([
        'lms_db_password' => 'secret123',
    ]);

    $rawRow = DB::table('pas_schools')->where('id', $school->id)->first();

    expect($rawRow)->not->toBeNull();
    expect((string) $rawRow->lms_db_password)->not->toBe('secret123');
    expect(strlen((string) $rawRow->lms_db_password))->toBeGreaterThan(strlen('secret123'));

    $reloaded = School::query()->findOrFail($school->id);

    expect($reloaded->lms_db_password)->toBe('secret123');
});

it('returns lms_db_database from getDatabaseName', function (): void {
    $school = School::factory()->create([
        'lms_db_database' => 'lms_acme',
    ]);

    expect($school->getDatabaseName())->toBe('lms_acme');
});
