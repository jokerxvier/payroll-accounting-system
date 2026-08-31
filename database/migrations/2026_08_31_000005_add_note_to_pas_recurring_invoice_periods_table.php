<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a claimed period raised no invoice.
 *
 * A claim with a null `invoice_id` says a period is spoken for and nothing was
 * billed; without a note it does not say why, and the operator is left with a
 * silent gap in a family's billing. The reason cannot live on the schedule:
 * one run can pass over August and successfully bill September, and the
 * schedule only has room for the latest state.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_recurring_invoice_periods')) {
            return;
        }

        if (Schema::hasColumn('pas_recurring_invoice_periods', 'note')) {
            return;
        }

        Schema::table('pas_recurring_invoice_periods', function (Blueprint $table): void {
            $table->string('note', 255)->nullable()->after('invoice_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pas_recurring_invoice_periods', 'note')) {
            return;
        }

        Schema::table('pas_recurring_invoice_periods', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
