<?php

declare(strict_types=1);

use App\Actions\Payroll\BuildBulkPayslipsZipAction;
use App\Jobs\Payroll\RenderPayslipPdfJob;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function authBulkPdfsAs(string $role): User
{
    config([
        'payroll.employee_role_allowlist' => [1, 4, 5],
        'payroll.lms_role_to_payroll_role' => [],
    ]);

    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('build endpoint dispatches one RenderPayslipPdfJob per payslip in a Bus batch', function () {
    Bus::fake();
    $user = authBulkPdfsAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();
    Payslip::factory()->count(3)->for($run, 'payrollRun')->create();

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/bulk-pdfs')
        ->assertRedirect('/admin/payroll-runs/'.$run->id)
        ->assertSessionHas('success');

    Bus::assertBatched(
        fn ($batch): bool => $batch->jobs->count() === 3
            && $batch->jobs->every(fn ($j): bool => $j instanceof RenderPayslipPdfJob),
    );
});

it('build endpoint short-circuits when the run has no payslips', function () {
    Bus::fake();
    $user = authBulkPdfsAs('super-admin');
    $run = PayrollRun::factory()->create();

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/bulk-pdfs')
        ->assertRedirect('/admin/payroll-runs/'.$run->id);

    Bus::assertNothingBatched();
});

it('build endpoint is forbidden for auditor and employee', function (string $role) {
    Bus::fake();
    $user = authBulkPdfsAs($role);
    $run = PayrollRun::factory()->computed()->create();

    $this->actingAs($user)
        ->post('/admin/payroll-runs/'.$run->id.'/bulk-pdfs')
        ->assertForbidden();
})->with(['auditor', 'employee']);

it('download endpoint streams the assembled zip when the run has one', function () {
    Storage::fake();
    $user = authBulkPdfsAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();

    // Pretend the action has already assembled a zip for this run.
    Storage::put($run->id.'/payslips.zip', 'PK\x03\x04 fake zip body');
    $run->forceFill([
        'bulk_pdf_zip_path' => $run->id.'/payslips.zip',
        'bulk_pdf_built_at' => now(),
    ])->save();

    $response = $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/bulk-pdfs');

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('.zip');
});

it('download endpoint returns 404 when no zip has been built', function () {
    $user = authBulkPdfsAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/bulk-pdfs')
        ->assertNotFound();
});

it('download endpoint returns 404 when the run claims a zip path but the file is gone', function () {
    Storage::fake();
    $user = authBulkPdfsAs('super-admin');
    $run = PayrollRun::factory()->computed()->create([
        'bulk_pdf_zip_path' => 'phantom/path.zip',
        'bulk_pdf_built_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/bulk-pdfs')
        ->assertNotFound();
});

it('download endpoint is forbidden for auditor and employee', function (string $role) {
    Storage::fake();
    $user = authBulkPdfsAs($role);
    $run = PayrollRun::factory()->computed()->create([
        'bulk_pdf_zip_path' => 'fake/path.zip',
    ]);

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id.'/bulk-pdfs')
        ->assertForbidden();
})->with(['auditor', 'employee']);

it('show payload includes has_bulk_pdf flag', function () {
    $user = authBulkPdfsAs('super-admin');
    $run = PayrollRun::factory()->computed()->create();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertInertia(
            fn ($page) => $page
                ->where('run.has_bulk_pdf', false),
        );

    $run->forceFill(['bulk_pdf_zip_path' => 'foo.zip', 'bulk_pdf_built_at' => now()])->save();

    $this->actingAs($user)
        ->get('/admin/payroll-runs/'.$run->id)
        ->assertInertia(
            fn ($page) => $page
                ->where('run.has_bulk_pdf', true),
        );
});

it('action assembleZip stamps the run with the artefact path on success', function () {
    Storage::fake();
    $run = PayrollRun::factory()->computed()->create();

    $tempDir = BuildBulkPayslipsZipAction::tempDir($run);
    Storage::put($tempDir.'/staff-1.pdf', '%PDF-1.4 fake one');
    Storage::put($tempDir.'/staff-2.pdf', '%PDF-1.4 fake two');

    BuildBulkPayslipsZipAction::assembleZip($run);

    $run->refresh();
    expect($run->bulk_pdf_zip_path)->toBe(BuildBulkPayslipsZipAction::artefactPath($run))
        ->and($run->bulk_pdf_built_at)->not->toBeNull()
        ->and(Storage::exists($run->bulk_pdf_zip_path))->toBeTrue();

    // Per-job temp dir is cleaned up after the zip lands.
    expect(Storage::files($tempDir))->toBe([]);
});
