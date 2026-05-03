<?php

declare(strict_types=1);

use App\Models\Pas\Allowance;
use App\Models\Pas\AuditLog;
use App\Models\Pas\DeductionType;
use App\Models\Pas\EmployeeAllowance;
use App\Models\Pas\EmployeeDeduction;
use App\Models\Pas\EmployeeLoan;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/*
 * Smoke test for the Auditable trait wiring on the six new Week 7 models.
 *
 * The trait + AuditObserver are exhaustively tested against EmployeeProfile
 * in tests/Feature/AuditLogTest.php. This file only proves that each new
 * model successfully boots the observer — one create call per model →
 * one row in pas_audit_logs with the correct auditable_type, action, and
 * before/after shape. Updates and deletes are exercised once on the
 * representative DeductionType to confirm the trait actually fires for
 * every event, not just `created`.
 */

function auditAuthedUser(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['payroll-officer']);
    Auth::login($user);

    return $user;
}

/**
 * Fetch the single audit log row for the given model, or fail the test
 * with a useful message if it isn't there. Wraps the null-check that
 * every assertion below would otherwise have to repeat.
 */
function latestAuditFor(Model $model, ?string $action = null): AuditLog
{
    $query = AuditLog::query()
        ->where('auditable_type', $model::class)
        ->where('auditable_id', $model->getKey());

    if ($action !== null) {
        $query->where('action', $action);
    }

    $row = $query->latest('id')->first();

    expect($row)->not->toBeNull(
        sprintf('expected an audit log row for %s#%s', $model::class, (string) $model->getKey()),
    );

    /** @var AuditLog $row */
    return $row;
}

it('writes a created audit row for DeductionType', function (): void {
    auditAuthedUser();
    $row = DeductionType::factory()->create();

    $log = latestAuditFor($row);

    /** @var array<string, mixed> $after */
    $after = $log->after ?? [];

    expect($log->action)->toBe('created')
        ->and($log->before)->toBeNull()
        ->and($after)->toBeArray()
        ->and($after['code'] ?? null)->toBe($row->code);
});

it('writes updated and deleted audit rows for DeductionType', function (): void {
    auditAuthedUser();
    $row = DeductionType::factory()->create(['name' => 'Original']);

    $row->update(['name' => 'Renamed']);

    $updated = latestAuditFor($row, 'updated');

    /** @var array<string, mixed> $before */
    $before = $updated->before ?? [];
    /** @var array<string, mixed> $after */
    $after = $updated->after ?? [];

    expect($before['name'] ?? null)->toBe('Original')
        ->and($after['name'] ?? null)->toBe('Renamed');

    $rowId = $row->id;
    $row->delete();

    $deleted = AuditLog::query()
        ->where('auditable_type', DeductionType::class)
        ->where('auditable_id', $rowId)
        ->where('action', 'deleted')
        ->first();

    expect($deleted)->not->toBeNull();
    /** @var AuditLog $deleted */
    expect($deleted->before)->toBeArray()
        ->and($deleted->after)->toBeNull();
});

it('writes a created audit row for Allowance', function (): void {
    auditAuthedUser();
    $row = Allowance::factory()->create();

    $log = latestAuditFor($row);
    /** @var array<string, mixed> $after */
    $after = $log->after ?? [];

    expect($log->action)->toBe('created')
        ->and($after['code'] ?? null)->toBe($row->code);
});

it('writes a created audit row for EmployeeDeduction', function (): void {
    auditAuthedUser();
    $row = EmployeeDeduction::factory()
        ->for(EmployeeProfile::factory())
        ->for(DeductionType::factory())
        ->create();

    $log = latestAuditFor($row);
    /** @var array<string, mixed> $after */
    $after = $log->after ?? [];

    expect($log->action)->toBe('created')
        ->and((int) ($after['employee_profile_id'] ?? 0))->toBe($row->employee_profile_id);
});

it('writes a created audit row for EmployeeAllowance', function (): void {
    auditAuthedUser();
    $row = EmployeeAllowance::factory()
        ->for(EmployeeProfile::factory())
        ->for(Allowance::factory())
        ->create();

    $log = latestAuditFor($row);
    /** @var array<string, mixed> $after */
    $after = $log->after ?? [];

    expect($log->action)->toBe('created')
        ->and((int) ($after['allowance_id'] ?? 0))->toBe($row->allowance_id);
});

it('writes a created audit row for EmployeeLoan', function (): void {
    auditAuthedUser();
    $row = EmployeeLoan::factory()
        ->for(EmployeeProfile::factory())
        ->create();

    $log = latestAuditFor($row);
    /** @var array<string, mixed> $after */
    $after = $log->after ?? [];

    expect($log->action)->toBe('created')
        ->and((int) ($after['principal_centavos'] ?? 0))->toBe($row->principal_centavos);
});

it('writes a created audit row for PayrollAdjustment', function (): void {
    auditAuthedUser();
    $row = PayrollAdjustment::factory()
        ->for(EmployeeProfile::factory())
        ->create();

    $log = latestAuditFor($row);
    /** @var array<string, mixed> $after */
    $after = $log->after ?? [];

    expect($log->action)->toBe('created')
        ->and(($after['kind'] ?? null))->toBe($row->kind);
});
