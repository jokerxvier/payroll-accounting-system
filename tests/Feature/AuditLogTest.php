<?php

declare(strict_types=1);

use App\Models\Pas\AuditLog;
use App\Models\Pas\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * The audit log infrastructure is generic — App\Concerns\Auditable +
 * App\Observers\AuditObserver. EmployeeProfile is the first model to opt in.
 *
 * Tests use sqlite in-memory + RefreshDatabase against the default connection.
 * pas_audit_logs has DEFAULT CURRENT_TIMESTAMP on created_at; the observer
 * sets it explicitly via now() so the value is deterministic.
 */

function authedPayrollUser(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['payroll-officer']);

    return $user;
}

it('writes a created audit row with full attribute snapshot', function () {
    $user = authedPayrollUser();
    Auth::login($user);

    $profile = EmployeeProfile::factory()->create([
        'employment_classification' => 'regular',
        'pay_frequency' => 'monthly',
        'basic_salary_centavos' => 5_000_000,
        'is_active' => true,
    ]);

    $rows = AuditLog::query()
        ->where('auditable_type', EmployeeProfile::class)
        ->where('auditable_id', $profile->id)
        ->get();

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->action)->toBe('created')
        ->and($row->actor_id)->toBe($user->id)
        ->and($row->before)->toBeNull()
        ->and($row->after)->toBeArray()
        ->and($row->after['employment_classification'] ?? null)->toBe('regular')
        ->and($row->after['pay_frequency'] ?? null)->toBe('monthly')
        ->and((int) ($row->after['basic_salary_centavos'] ?? 0))->toBe(5_000_000)
        ->and($row->created_at)->not->toBeNull();
});

it('writes an updated audit row containing only the changed columns', function () {
    Auth::login(authedPayrollUser());

    $profile = EmployeeProfile::factory()->create([
        'basic_salary_centavos' => 4_000_000,
    ]);

    // Drop the created row so we can target the update row in isolation.
    $createdRow = AuditLog::query()
        ->where('auditable_type', EmployeeProfile::class)
        ->where('auditable_id', $profile->id)
        ->first();
    expect($createdRow)->not->toBeNull();

    $profile->update(['basic_salary_centavos' => 99_999_900]);

    $updateRows = AuditLog::query()
        ->where('auditable_type', EmployeeProfile::class)
        ->where('auditable_id', $profile->id)
        ->where('action', 'updated')
        ->get();

    expect($updateRows)->toHaveCount(1);

    $row = $updateRows->first();

    expect($row->before)->toBeArray()
        ->and(array_keys($row->before))->toBe(['basic_salary_centavos'])
        ->and((int) $row->before['basic_salary_centavos'])->toBe(4_000_000)
        ->and($row->after)->toBeArray()
        ->and(array_keys($row->after))->toBe(['basic_salary_centavos'])
        ->and((int) $row->after['basic_salary_centavos'])->toBe(99_999_900);
});

it('captures multiple columns in a single update audit row', function () {
    Auth::login(authedPayrollUser());

    $profile = EmployeeProfile::factory()->create([
        'employment_classification' => 'probationary',
        'basic_salary_centavos' => 3_000_000,
    ]);

    $profile->update([
        'employment_classification' => 'regular',
        'basic_salary_centavos' => 5_000_000,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', EmployeeProfile::class)
        ->where('auditable_id', $profile->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->before)->toBeArray()
        ->and($row->after)->toBeArray();

    $beforeKeys = array_keys($row->before);
    $afterKeys = array_keys($row->after);

    sort($beforeKeys);
    sort($afterKeys);

    expect($beforeKeys)->toBe(['basic_salary_centavos', 'employment_classification'])
        ->and($afterKeys)->toBe(['basic_salary_centavos', 'employment_classification'])
        ->and($row->before['employment_classification'])->toBe('probationary')
        ->and($row->after['employment_classification'])->toBe('regular')
        ->and((int) $row->before['basic_salary_centavos'])->toBe(3_000_000)
        ->and((int) $row->after['basic_salary_centavos'])->toBe(5_000_000);
});

it('writes a deleted audit row with the full pre-delete snapshot', function () {
    Auth::login(authedPayrollUser());

    $profile = EmployeeProfile::factory()->create([
        'employment_classification' => 'contractual',
        'basic_salary_centavos' => 2_500_000,
    ]);
    $profileId = $profile->id;

    $profile->delete();

    $row = AuditLog::query()
        ->where('auditable_type', EmployeeProfile::class)
        ->where('auditable_id', $profileId)
        ->where('action', 'deleted')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->before)->toBeArray()
        ->and($row->after)->toBeNull()
        ->and($row->before['employment_classification'] ?? null)->toBe('contractual')
        ->and((int) ($row->before['basic_salary_centavos'] ?? 0))->toBe(2_500_000);
});

it('records anonymous writes with a null actor_id and does not crash', function () {
    Auth::logout();
    expect(Auth::id())->toBeNull();

    $profile = EmployeeProfile::factory()->create();

    $row = AuditLog::query()
        ->where('auditable_type', EmployeeProfile::class)
        ->where('auditable_id', $profile->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->action)->toBe('created')
        ->and($row->actor_id)->toBeNull();
});

it('blocks updates on audit log entries', function () {
    Auth::login(authedPayrollUser());

    $profile = EmployeeProfile::factory()->create();
    $row = AuditLog::query()->where('auditable_id', $profile->id)->first();
    expect($row)->not->toBeNull();

    expect(fn () => $row->update(['action' => 'tampered']))
        ->toThrow(LogicException::class, 'Audit logs are append-only.');

    // The DB row must be untouched.
    $raw = DB::table('pas_audit_logs')->where('id', $row->id)->first();
    expect($raw->action)->toBe('created');
});

it('blocks deletes on audit log entries', function () {
    Auth::login(authedPayrollUser());

    $profile = EmployeeProfile::factory()->create();
    $row = AuditLog::query()->where('auditable_id', $profile->id)->first();
    expect($row)->not->toBeNull();

    expect(fn () => $row->delete())
        ->toThrow(LogicException::class, 'Audit logs are append-only.');

    expect(DB::table('pas_audit_logs')->where('id', $row->id)->exists())->toBeTrue();
});
