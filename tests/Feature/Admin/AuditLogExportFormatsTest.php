<?php

declare(strict_types=1);

use App\Exports\AuditLogExport;
use App\Models\Pas\Allowance;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;

/*
 * Audit-log export format parity (Phase 4 acceptance criterion).
 *
 * W14 Stage B shipped CSV only. The criterion names the audit log as one of
 * the three reports that must export to all three formats.
 *
 * CSV stays the default: the existing export link carries no `format`
 * parameter, and CSV is what an auditor handoff or retention archive wants.
 */

function auditExportAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

function seedAuditEntries(): void
{
    // Allowance is Auditable, so creating and updating one writes real
    // audit rows rather than hand-inserting them.
    $allowance = Allowance::factory()->create(['code' => 'audit_fmt_alw']);
    $allowance->update(['name' => 'Renamed for the audit trail']);
}

it('defaults the audit export to csv when no format is given', function () {
    Excel::fake();
    seedAuditEntries();

    $this->actingAs(auditExportAuthAs('auditor'))
        ->get('/admin/audit-logs/export')
        ->assertOk();

    Excel::assertDownloaded(
        sprintf('audit-log_all_%s.csv', now()->toDateString()),
        fn ($export): bool => $export instanceof AuditLogExport,
    );
});

it('exports the audit log as xlsx', function () {
    Excel::fake();
    seedAuditEntries();

    $this->actingAs(auditExportAuthAs('auditor'))
        ->get('/admin/audit-logs/export?format=xlsx')
        ->assertOk();

    Excel::assertDownloaded(
        sprintf('audit-log_all_%s.xlsx', now()->toDateString()),
        fn ($export): bool => $export instanceof AuditLogExport,
    );
});

it('exports the audit log as a pdf', function () {
    seedAuditEntries();

    $response = $this->actingAs(auditExportAuthAs('auditor'))
        ->get('/admin/audit-logs/export?format=pdf')
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect($response->headers->get('content-disposition'))
        ->toContain(sprintf('audit-log_all_%s.pdf', now()->toDateString()));

    $body = $response->getContent();
    expect(substr($body, 0, 4))->toBe('%PDF');
    expect(strlen($body))->toBeGreaterThan(1000);
});

it('renders an audit pdf when nothing matches the filters', function () {
    $response = $this->actingAs(auditExportAuthAs('auditor'))
        ->get('/admin/audit-logs/export?format=pdf&from=2030-01-01&to=2030-01-31')
        ->assertOk();

    expect(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});

it('rejects an unrecognised audit export format', function () {
    $this->actingAs(auditExportAuthAs('auditor'))
        ->get('/admin/audit-logs/export?format=docx')
        ->assertStatus(422);
});

it('keeps every audit export format behind the audit roles', function (string $format) {
    $this->actingAs(auditExportAuthAs('payroll-officer'))
        ->get("/admin/audit-logs/export?format={$format}")
        ->assertForbidden();
})->with(['csv', 'xlsx', 'pdf']);
