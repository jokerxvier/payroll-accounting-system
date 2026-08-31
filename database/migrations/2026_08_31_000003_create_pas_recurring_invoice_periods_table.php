<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per period a schedule has already billed. The never-bill-twice
 * guarantee, held in the schema rather than in a code path that can be skipped
 * — the same shape as `pas_gateway_events`' `(provider, external_event_id)`.
 *
 * **Why this is its own table and not two columns on `pas_invoices`.** The
 * invoice's lifecycle is the wrong lifecycle for the claim. Put on the invoice,
 * the constraint does two opposite and equally wrong things: deleting a
 * wrongly-generated draft releases the period, so the job regenerates it that
 * night and the operator's deletion is undone while they sleep; and voiding an
 * invoice consumes the period for good, so a document that has to be reissued
 * can only be raised by hand, with nothing on screen saying why. A claim that
 * outlives the document makes deleting safe and makes re-billing deliberate.
 *
 * `invoice_id` is nullable and `nullOnDelete` on purpose: the claim is the
 * point, the invoice is the evidence, and losing the evidence must not release
 * the claim.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_recurring_invoice_periods')) {
            return;
        }

        Schema::create('pas_recurring_invoice_periods', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('recurring_invoice_id');

            // 'YYYY-MM' for monthly and quarterly, 'YYYY' for annual. This is
            // the idempotency key, so the format is a contract: changing it
            // re-opens every schedule to double-billing for one cycle.
            $table->string('period', 16);

            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamps();

            $table->foreign('school_id')
                ->references('id')->on('pas_schools')
                ->restrictOnDelete();

            $table->foreign('recurring_invoice_id')
                ->references('id')->on('pas_recurring_invoices')
                ->cascadeOnDelete();

            $table->foreign('invoice_id')
                ->references('id')->on('pas_invoices')
                ->nullOnDelete();

            // The guarantee. Named explicitly: the generated name would be 68
            // characters against MySQL's 64-character limit, and SQLite would
            // accept it — so the failure would only ever appear in production.
            $table->unique(
                ['school_id', 'recurring_invoice_id', 'period'],
                'pas_recinvp_schedule_period_unq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_recurring_invoice_periods');
    }
};
