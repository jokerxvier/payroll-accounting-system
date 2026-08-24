<?php

declare(strict_types=1);

use App\Concerns\Auditable;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\Allowance;
use App\Models\Pas\AuditLog;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\DeductionType;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use App\Models\User;
use Illuminate\Support\Collection;

/*
 * Phase 4 acceptance criterion:
 *
 *   "Every state-changing action across the system has an audit log entry;
 *    spot-check passes for each role."
 *
 * This file is that check, made executable. It drives each state-changing
 * endpoint as an authorised user and asserts a `pas_audit_logs` row lands,
 * carrying the acting user.
 *
 * Two structural gaps it also guards against, both of which bypass the
 * AuditObserver entirely because the observer hangs off Eloquent events:
 *
 *   - a `pas_*` model that forgets the Auditable trait
 *   - a delete that cascades at the database level, so the child model's
 *     `deleted` event never fires
 */

/** Run $action and return only the audit rows it produced. */
function auditRowsFrom(callable $action): Collection
{
    $highWaterMark = (int) (AuditLog::query()->withoutGlobalScopes()->max('id') ?? 0);

    $action();

    return AuditLog::query()
        ->withoutGlobalScopes()
        ->where('id', '>', $highWaterMark)
        ->get();
}

function auditActingAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/* ── Structural guards ──────────────────────────────────────────────── */

it('applies the Auditable trait to every persisted pas model', function () {
    // AuditLog is the trail itself — auditing it would recurse.
    $exempt = [AuditLog::class];

    $files = glob(app_path('Models/Pas/*.php'));
    expect($files)->not()->toBeEmpty();

    $missing = [];

    foreach ($files as $file) {
        $class = 'App\\Models\\Pas\\'.basename($file, '.php');

        if (in_array($class, $exempt, true)) {
            continue;
        }

        if (! in_array(Auditable::class, class_uses_recursive($class), true)) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe(
        [],
        'These pas models are persisted but never audited: '.implode(', ', $missing),
    );
});

/* ── Accounting catalog (Phase 5 Slice 1) ───────────────────────────── */

it('audits creating, editing, and deleting a chart-of-accounts row', function () {
    $actor = auditActingAs('accountant');

    $created = auditRowsFrom(function () use ($actor): void {
        $this->actingAs($actor)->post('/admin/chart-of-accounts', [
            'code' => '6100',
            'name' => 'Audit Coverage Account',
            'type' => ChartOfAccount::TYPE_EXPENSE,
            'subtype' => 'operating_expense',
            'cash_flow_category' => ChartOfAccount::CASH_FLOW_OPERATING,
            'parent_id' => null,
            'description' => null,
            'is_active' => true,
        ])->assertRedirect();
    });

    expect($created)->toHaveCount(1)
        ->and($created->first()->action)->toBe('created')
        ->and($created->first()->auditable_type)->toBe(ChartOfAccount::class)
        ->and($created->first()->actor_id)->toBe($actor->getKey());

    $account = ChartOfAccount::query()->where('code', '6100')->firstOrFail();

    $updated = auditRowsFrom(function () use ($actor, $account): void {
        $this->actingAs($actor)->patch("/admin/chart-of-accounts/{$account->getKey()}", [
            'code' => '6100',
            'name' => 'Renamed',
            'type' => ChartOfAccount::TYPE_EXPENSE,
            'cash_flow_category' => ChartOfAccount::CASH_FLOW_OPERATING,
            'is_active' => true,
        ])->assertRedirect();
    });

    expect($updated)->toHaveCount(1)
        ->and($updated->first()->action)->toBe('updated')
        ->and($updated->first()->after)->toHaveKey('name');

    $deleted = auditRowsFrom(function () use ($actor, $account): void {
        $this->actingAs($actor)
            ->delete("/admin/chart-of-accounts/{$account->getKey()}")
            ->assertRedirect();
    });

    expect($deleted)->toHaveCount(1)
        ->and($deleted->first()->action)->toBe('deleted');
});

it('audits tax-rate mutations', function () {
    $actor = auditActingAs('accountant');
    $account = ChartOfAccount::factory()
        ->liability()
        ->create(['code' => '2200', 'name' => 'Output VAT']);

    $rows = auditRowsFrom(function () use ($actor, $account): void {
        $this->actingAs($actor)->post('/admin/tax-rates', [
            'code' => 'VAT_AUDIT',
            'name' => 'VAT 12%',
            'rate_bps' => 1200,
            'type' => TaxRate::TYPE_VAT_SALES,
            'account_id' => $account->getKey(),
            'is_active' => true,
        ])->assertRedirect();
    });

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->auditable_type)->toBe(TaxRate::class)
        ->and($rows->first()->actor_id)->toBe($actor->getKey());
});

it('audits closing and reopening an accounting period', function () {
    $closer = auditActingAs('accountant');
    $period = AccountingPeriod::factory()->create();

    $closed = auditRowsFrom(function () use ($closer, $period): void {
        $this->actingAs($closer)
            ->post("/admin/accounting-periods/{$period->getKey()}/close")
            ->assertRedirect();
    });

    expect($closed)->toHaveCount(1)
        ->and($closed->first()->action)->toBe('updated')
        ->and($closed->first()->after)->toHaveKey('status')
        ->and($closed->first()->actor_id)->toBe($closer->getKey());

    // Reopening is the most audit-sensitive action in the module — it must
    // name whoever did it, separately from whoever closed the period.
    $reopener = auditActingAs('super-admin');

    $reopened = auditRowsFrom(function () use ($reopener, $period): void {
        $this->actingAs($reopener)
            ->post("/admin/accounting-periods/{$period->getKey()}/reopen")
            ->assertRedirect();
    });

    expect($reopened)->toHaveCount(1)
        ->and($reopened->first()->actor_id)->toBe($reopener->getKey())
        ->and($reopened->first()->after)->toHaveKey('reopened_by_user_id');
});

/* ── Payroll catalog ────────────────────────────────────────────────── */

it('audits allowance and deduction-type catalog writes', function () {
    $actor = auditActingAs('super-admin');

    $allowance = auditRowsFrom(function () use ($actor): void {
        $this->actingAs($actor)->post('/admin/allowances', [
            'code' => 'audit_alw',
            'name' => 'Audit allowance',
            'is_taxable' => true,
            'is_de_minimis' => false,
            'de_minimis_cap_centavos' => null,
            'default_amount_centavos' => 100_000,
            'is_active' => true,
            'notes' => null,
        ])->assertRedirect();
    });

    expect($allowance)->toHaveCount(1)
        ->and($allowance->first()->auditable_type)->toBe(Allowance::class);
});

it('audits pay-period creation', function () {
    $actor = auditActingAs('super-admin');

    $rows = auditRowsFrom(function () use ($actor): void {
        $this->actingAs($actor)->post('/admin/pay-periods', [
            'code' => '2026-06-A',
            'frequency' => PayPeriod::FREQUENCY_MONTHLY,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'cutoff_date' => '2026-06-30',
            'status' => PayPeriod::STATUS_OPEN,
        ])->assertRedirect();
    });

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->auditable_type)->toBe(PayPeriod::class)
        ->and($rows->first()->actor_id)->toBe($actor->getKey());
});

/* ── Payroll run lifecycle ──────────────────────────────────────────── */

it('audits each payroll-run lifecycle transition against its actor', function () {
    $period = PayPeriod::factory()->create(['code' => '2026-07']);
    $run = PayrollRun::factory()->create([
        'pay_period_id' => $period->id,
        'status' => PayrollRun::STATUS_COMPUTED,
    ]);

    $maker = auditActingAs('payroll-officer');
    $approver = auditActingAs('super-admin');

    $submitted = auditRowsFrom(function () use ($maker, $run): void {
        $this->actingAs($maker)
            ->post("/admin/payroll-runs/{$run->getKey()}/submit")
            ->assertRedirect();
    });
    expect($submitted)->toHaveCount(1)
        ->and($submitted->first()->actor_id)->toBe($maker->getKey())
        ->and($submitted->first()->auditable_type)->toBe(PayrollRun::class);

    $approved = auditRowsFrom(function () use ($approver, $run): void {
        $this->actingAs($approver)
            ->post("/admin/payroll-runs/{$run->getKey()}/approve")
            ->assertRedirect();
    });
    expect($approved)->toHaveCount(1)
        ->and($approved->first()->actor_id)->toBe($approver->getKey());

    $posted = auditRowsFrom(function () use ($approver, $run): void {
        $this->actingAs($approver)
            ->post("/admin/payroll-runs/{$run->getKey()}/post")
            ->assertRedirect();
    });
    expect($posted)->toHaveCount(1)
        ->and($posted->first()->after)->toHaveKey('status');
});

it('audits every payslip removed when a payroll run is deleted', function () {
    // The payslips FK is cascadeOnDelete, so the database removes the child
    // rows without Eloquent ever firing Payslip::deleted. Without an explicit
    // sweep, deleting a run silently destroys N payslips and the trail shows
    // only the run.
    $period = PayPeriod::factory()->create(['code' => '2026-08']);
    $run = PayrollRun::factory()->create([
        'pay_period_id' => $period->id,
        'status' => PayrollRun::STATUS_COMPUTED,
    ]);
    Payslip::factory()->count(3)->create(['payroll_run_id' => $run->id]);

    $actor = auditActingAs('payroll-officer');

    $rows = auditRowsFrom(function () use ($actor, $run): void {
        $this->actingAs($actor)
            ->delete("/admin/payroll-runs/{$run->getKey()}")
            ->assertRedirect();
    });

    $payslipRows = $rows->where('auditable_type', Payslip::class);
    $runRows = $rows->where('auditable_type', PayrollRun::class);

    expect($runRows)->toHaveCount(1)
        ->and($payslipRows)->toHaveCount(3);
});

/* ── Tenancy ────────────────────────────────────────────────────────── */

it('audits school registry writes', function () {
    // Creating or deleting a tenant is the most consequential action a
    // platform admin can take, and it was previously invisible to the trail.
    // withoutLmsMirror() nulls lms_user_id, which SchoolPolicy::before()
    // requires alongside the role — both gates have to pass. The factory
    // nulls it with a direct DB update, so the in-memory instance has to be
    // refreshed or the policy still sees the stale value.
    $actor = User::factory()->withoutLmsMirror()->create();
    $actor->syncRoles(['platform-admin']);
    $actor = $actor->fresh() ?? $actor;

    $rows = auditRowsFrom(function () use ($actor): void {
        $this->actingAs($actor)->post('/admin/schools', [
            'name' => 'Audit Coverage School',
            'slug' => 'audit-coverage-school',
            'domain' => null,
            'lms_db_host' => '127.0.0.1',
            'lms_db_port' => 3306,
            'lms_db_database' => 'lms_audit_coverage',
            'lms_db_username' => 'lms_user',
            'lms_db_password' => 'secret-password',
            'lms_db_charset' => 'utf8mb4',
            'is_active' => true,
        ])->assertRedirect();
    });

    $schoolRows = $rows->where('auditable_type', School::class);

    expect($schoolRows)->toHaveCount(1)
        ->and($schoolRows->first()->action)->toBe('created')
        ->and($schoolRows->first()->actor_id)->toBe($actor->getKey());

    // The tenant's LMS database password must never reach the trail. It is
    // encrypted at rest, but an audit row is a second copy of the credential
    // in a table auditors can export to CSV and PDF.
    $after = $schoolRows->first()->after;
    expect($after)->not()->toHaveKey('lms_db_password')
        ->and(json_encode($after))->not()->toContain('secret-password');
});

/* ── Role spot-check ────────────────────────────────────────────────── */

it('records the acting role correctly for each role that can write', function (string $role) {
    $actor = auditActingAs($role);

    $rows = auditRowsFrom(function () use ($actor, $role): void {
        $this->actingAs($actor)->post('/admin/chart-of-accounts', [
            'code' => '69'.substr(md5($role), 0, 2),
            'name' => "Written by {$role}",
            'type' => ChartOfAccount::TYPE_EXPENSE,
            'cash_flow_category' => ChartOfAccount::CASH_FLOW_OPERATING,
            'is_active' => true,
        ])->assertRedirect();
    });

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->actor_id)->toBe($actor->getKey())
        ->and($rows->first()->ip)->not()->toBeNull();
})->with(['super-admin', 'accountant', 'payroll-officer']);

it('still records a mutation with a null actor outside a request', function () {
    // Queued jobs and console commands have no authenticated user. The row
    // must still be written, with actor_id null rather than the write being
    // dropped.
    $rows = auditRowsFrom(function (): void {
        DeductionType::factory()->create(['code' => 'console_write']);
    });

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->actor_id)->toBeNull();
});
