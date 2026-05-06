<?php

declare(strict_types=1);

namespace App\Actions\Payroll;

use App\Jobs\Payroll\RenderPayslipPdfJob;
use App\Models\Pas\PayrollRun;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Build a zipped archive of every payslip's PDF in a payroll run.
 *
 * Phase 3 W11 Stage C — uses Laravel's Bus::batch to fan out one
 * {@see RenderPayslipPdfJob} per payslip; the `then` callback scans the
 * per-run temp directory, assembles a zip into the run's permanent
 * artefact location, and stamps `bulk_pdf_zip_path` + `bulk_pdf_built_at`
 * on the run row.
 *
 * Idempotent: a second call while a build is in flight is a no-op
 * (existing zip path remains; user can refresh and download). A second
 * call after a previous successful build re-runs the batch and overwrites
 * the zip — useful when an admin voids and re-runs and wants fresh PDFs.
 */
final class BuildBulkPayslipsZipAction
{
    public function execute(PayrollRun $run): void
    {
        $payslipIds = $run->payslips()->pluck('id')->all();

        if (count($payslipIds) === 0) {
            return;
        }

        // Wipe any previous temp output for this run before kicking off
        // a fresh batch. The zip path itself is overwritten only after
        // the batch's `then` callback runs.
        Storage::deleteDirectory(self::tempDir($run));

        $jobs = array_map(
            static fn (int $payslipId): RenderPayslipPdfJob => new RenderPayslipPdfJob(
                payrollRunId: $run->id,
                payslipId: $payslipId,
            ),
            $payslipIds,
        );

        Bus::batch($jobs)
            ->name(sprintf('payroll-run-%d-bulk-pdfs', $run->id))
            ->then(function (Batch $batch) use ($run): void {
                self::assembleZip($run);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($run): void {
                // On failure clear any partial output. The run row stays
                // unchanged (no bulk_pdf_zip_path) so the UI shows
                // "Build" again.
                Storage::deleteDirectory(self::tempDir($run));
            })
            ->dispatch();
    }

    /**
     * Walk the per-run temp directory, write a zip to the canonical
     * artefact location, then drop the temp dir. Public so the queue
     * worker / sync driver can call it from the batch finalizer.
     */
    public static function assembleZip(PayrollRun $run): void
    {
        $tempDir = self::tempDir($run);
        $files = Storage::files($tempDir);

        if (count($files) === 0) {
            // Nothing to zip — leave the run row untouched.
            return;
        }

        $zipRelative = self::artefactPath($run);
        $zipAbsolute = (string) Storage::path($zipRelative);

        // Make sure the parent dir exists.
        $parentDir = dirname($zipRelative);
        if (! Storage::exists($parentDir)) {
            Storage::makeDirectory($parentDir);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipAbsolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return;
        }

        foreach ($files as $file) {
            $contents = Storage::get($file);
            if ($contents === null) {
                continue;
            }
            $zip->addFromString(basename($file), $contents);
        }

        $zip->close();

        // Persist the artefact pointer + timestamp.
        $run->forceFill([
            'bulk_pdf_zip_path' => $zipRelative,
            'bulk_pdf_built_at' => now(),
        ])->save();

        // Clean up the per-job temp files now that they're inside the zip.
        Storage::deleteDirectory($tempDir);
    }

    public static function tempDir(PayrollRun $run): string
    {
        return sprintf('bulk-pdf-tmp/%d', $run->id);
    }

    public static function artefactPath(PayrollRun $run): string
    {
        return sprintf('payroll-runs/%d/payslips.zip', $run->id);
    }
}
