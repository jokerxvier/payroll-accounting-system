<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 7 — which invoices a payment was applied to.
 *
 * The join that makes partial and multi-invoice payment work: one payment can
 * settle several documents, and one document can be settled by several
 * payments over time.
 *
 * Unique on `(payment_id, invoice_id)`. A payment applying to the same
 * invoice twice is a merge, not two rows — two rows would make the allocated
 * total right while leaving the audit trail ambiguous about what was actually
 * agreed.
 *
 * `payment_id` is `cascadeOnDelete` so orphaned allocations are impossible,
 * with PaymentObserver deleting them through Eloquent first so each still
 * writes an audit row. Same trap as invoice lines, journal lines, and the
 * payroll-run delete that used to destroy payslips silently.
 *
 * `invoice_id` is `restrictOnDelete`: an invoice that has been paid cannot be
 * deleted. In practice InvoicePolicy already refuses to delete anything but a
 * draft, and a draft cannot be allocated against — this is the backstop.
 *
 * Allocations are never deleted when a payment is voided. They stay as the
 * record of what was applied, and stop counting because
 * InvoiceBalanceService only sums allocations belonging to *posted* payments.
 * Undoing a payment therefore destroys nothing.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_payment_allocations')) {
            return;
        }

        Schema::create('pas_payment_allocations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('invoice_id');
            $table->bigInteger('amount_centavos')->default(0);
            $table->timestamps();

            $table->unique(['payment_id', 'invoice_id'], 'pas_payalloc_payment_invoice_unq');
            // The invoice detail page and the balance recomputation both walk
            // allocations by invoice.
            $table->index(['school_id', 'invoice_id'], 'pas_payalloc_school_invoice_idx');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();

            $table->foreign('payment_id')
                ->references('id')
                ->on('pas_payments')
                ->cascadeOnDelete();

            $table->foreign('invoice_id')
                ->references('id')
                ->on('pas_invoices')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_payment_allocations');
    }
};
