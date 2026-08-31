<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an invoice say which schedule raised it.
 *
 * Carries no uniqueness — the never-bill-twice guarantee lives in
 * `pas_recurring_invoice_periods`, for the lifecycle reasons set out there.
 * This column exists so the list can mark a generated invoice, so an officer
 * reviewing forty drafts can see where they came from, and so the approval
 * email fires only for invoices a schedule produced.
 *
 * `nullOnDelete`: deleting a schedule must not delete the invoices it raised.
 * Those are issued documents.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_invoices')) {
            return;
        }

        if (Schema::hasColumn('pas_invoices', 'recurring_invoice_id')) {
            return;
        }

        Schema::table('pas_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('recurring_invoice_id')
                ->nullable()
                ->after('type');

            $table->foreign('recurring_invoice_id')
                ->references('id')->on('pas_recurring_invoices')
                ->nullOnDelete();

            $table->index(['school_id', 'recurring_invoice_id'], 'pas_inv_school_recurring_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pas_invoices', 'recurring_invoice_id')) {
            return;
        }

        Schema::table('pas_invoices', function (Blueprint $table): void {
            $table->dropForeign(['recurring_invoice_id']);
            $table->dropIndex('pas_inv_school_recurring_idx');
            $table->dropColumn('recurring_invoice_id');
        });
    }
};
