<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The template lines a schedule copies onto every invoice it raises.
 *
 * Mirrors `pas_invoice_lines` minus the computed `line_net` / `line_tax`:
 * those are derived at generation by `InvoiceTotalsCalculator`, from whatever
 * the tax rate says on the day. A schedule that stored them would keep billing
 * last year's VAT after a rate changed.
 *
 * `cascadeOnDelete` with a `deleting` observer that removes the lines through
 * Eloquent first — the same pairing as `pas_invoice_lines`. The FK alone would
 * let the database drop the rows without firing `deleted`, and the audit trail
 * would show a schedule disappearing and nothing about what it charged.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_recurring_invoice_lines')) {
            return;
        }

        Schema::create('pas_recurring_invoice_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('recurring_invoice_id');

            $table->unsignedInteger('line_number')->default(1);
            $table->string('description', 255);

            // decimal, not float, and deliberately not cast on the model: the
            // calculator parses the string into ten-thousandths rather than
            // multiplying money by a float.
            $table->decimal('quantity', 12, 4)->default(1);
            $table->bigInteger('unit_price_centavos')->default(0);

            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('tax_rate_id')->nullable();

            $table->timestamps();

            $table->foreign('school_id')
                ->references('id')->on('pas_schools')
                ->restrictOnDelete();

            $table->foreign('recurring_invoice_id')
                ->references('id')->on('pas_recurring_invoices')
                ->cascadeOnDelete();

            $table->foreign('account_id')
                ->references('id')->on('pas_chart_of_accounts')
                ->restrictOnDelete();

            // A deleted tax rate leaves the line un-taxed rather than taking
            // the schedule down with it.
            $table->foreign('tax_rate_id')
                ->references('id')->on('pas_tax_rates')
                ->nullOnDelete();

            $table->index(['recurring_invoice_id', 'line_number'], 'pas_recinvl_schedule_line_idx');
            $table->index(['school_id', 'account_id'], 'pas_recinvl_school_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_recurring_invoice_lines');
    }
};
