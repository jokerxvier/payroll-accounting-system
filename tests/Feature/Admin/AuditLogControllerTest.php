<?php

declare(strict_types=1);

use App\Exports\AuditLogExport;
use App\Models\Pas\AuditLog;
use App\Models\Pas\EmployeeProfile;
use App\Models\Pas\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

function authAuditAs(string $role): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('renders the audit log index for super-admin and auditor', function (string $role) {
    $user = authAuditAs($role);
    $actor = User::factory()->create();
    AuditLog::query()->create([
        'auditable_type' => EmployeeProfile::class,
        'auditable_id' => 1,
        'actor_id' => $actor->id,
        'action' => 'updated',
        'before' => ['x' => 1],
        'after' => ['x' => 2],
        'created_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->get('/admin/audit-logs')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/audit-logs/index')
                ->has('entries.data', 1)
                ->where('entries.data.0.action', 'updated')
                ->where('entries.data.0.auditable_short_type', 'EmployeeProfile')
                ->has('distinctActions')
                ->has('distinctAuditableTypes'),
        );
})->with(['super-admin', 'auditor']);

it('forbids the audit log for non-audit roles', function (string $role) {
    $user = authAuditAs($role);
    $this->actingAs($user)
        ->get('/admin/audit-logs')
        ->assertForbidden();
})->with(['payroll-officer', 'hr', 'employee']);

it('filters by action', function () {
    $user = authAuditAs('super-admin');

    AuditLog::query()->create([
        'auditable_type' => EmployeeProfile::class,
        'auditable_id' => 1,
        'action' => 'created',
        'created_at' => now()->subHour(),
    ]);
    AuditLog::query()->create([
        'auditable_type' => EmployeeProfile::class,
        'auditable_id' => 1,
        'action' => 'updated',
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin/audit-logs?action=created')
        ->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.action', 'created'),
        );
});

it('filters by date range', function () {
    $user = authAuditAs('super-admin');

    AuditLog::query()->create([
        'auditable_type' => EmployeeProfile::class,
        'action' => 'a',
        'created_at' => '2026-04-15 10:00:00',
    ]);
    AuditLog::query()->create([
        'auditable_type' => EmployeeProfile::class,
        'action' => 'b',
        'created_at' => '2026-05-15 10:00:00',
    ]);

    $this->actingAs($user)
        ->get('/admin/audit-logs?from=2026-05-01&to=2026-05-31')
        ->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.action', 'b'),
        );
});

it('filters by auditable_type', function () {
    $user = authAuditAs('super-admin');

    AuditLog::query()->create([
        'auditable_type' => EmployeeProfile::class,
        'action' => 'a',
        'created_at' => now(),
    ]);
    AuditLog::query()->create([
        'auditable_type' => PayrollRun::class,
        'action' => 'b',
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin/audit-logs?auditable_type='.urlencode(PayrollRun::class))
        ->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.action', 'b'),
        );
});

it('paginates at 25 per page', function () {
    $user = authAuditAs('super-admin');
    for ($i = 0; $i < 30; $i++) {
        AuditLog::query()->create([
            'auditable_type' => EmployeeProfile::class,
            'action' => 'updated',
            'created_at' => now()->subSeconds($i),
        ]);
    }

    $this->actingAs($user)
        ->get('/admin/audit-logs')
        ->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 25)
                ->where('entries.total', 30)
                ->where('entries.last_page', 2),
        );
});

it('downloads the CSV export via Excel::fake', function () {
    Excel::fake();
    $user = authAuditAs('auditor');
    AuditLog::query()->create([
        'auditable_type' => EmployeeProfile::class,
        'action' => 'updated',
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin/audit-logs/export?from=2026-05-01&to=2026-05-31')
        ->assertOk();

    Excel::assertDownloaded(
        'audit-log_2026-05-01_2026-05-31.csv',
        fn ($export): bool => $export instanceof AuditLogExport,
    );
});

it('forbids the CSV export for non-audit roles', function (string $role) {
    Excel::fake();
    $user = authAuditAs($role);

    $this->actingAs($user)
        ->get('/admin/audit-logs/export')
        ->assertForbidden();
})->with(['payroll-officer', 'hr', 'employee']);
