<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 W11 Stage C — track the bulk-payslips zip artefact per run.
 *
 *   bulk_pdf_zip_path     — relative path under the configured storage
 *                            disk where the assembled zip lives once
 *                            built. Null until the queued build finishes.
 *   bulk_pdf_built_at     — timestamp the build finished. UI uses this
 *                            to decide whether to show "Build" or
 *                            "Download" on the run page.
 *
 * pas_* prefix only — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pas_payroll_runs', function (Blueprint $table): void {
            $table->string('bulk_pdf_zip_path', 255)->nullable()->after('voided_by_user_id');
            $table->timestamp('bulk_pdf_built_at')->nullable()->after('bulk_pdf_zip_path');
        });
    }

    public function down(): void
    {
        Schema::table('pas_payroll_runs', function (Blueprint $table): void {
            $table->dropColumn(['bulk_pdf_zip_path', 'bulk_pdf_built_at']);
        });
    }
};
