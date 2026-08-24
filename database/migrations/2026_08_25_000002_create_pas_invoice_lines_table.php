<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 5 — the lines of an invoice.
 *
 * `quantity` is a decimal rather than an integer: tuition is billed in whole
 * units but supplier bills routinely carry fractional hours or partial
 * months. Four decimal places is enough for any of those without inviting
 * the float drift the Money value object exists to prevent — the money
 * columns stay integer centavos throughout, and `quantity` is only ever a
 * multiplier feeding a single rounding step inside the calculator.
 *
 * `line_net_centavos` and `line_tax_centavos` are computed and stored rather
 * than derived on read. Two reasons: the invoice total must equal the sum of
 * the lines a customer can add up on the printed page, and a stored figure
 * cannot drift when a tax rate is later edited. A posted invoice shows the
 * tax that was actually charged, not what the current rate would produce.
 *
 * `account_id` is the income account credited on a sale, or the expense
 * account debited on a purchase — per line, so a single invoice can split
 * across several. `restrictOnDelete` for the same reason journal lines do:
 * an account referenced by a document is history.
 *
 * `tax_rate_id` is nullable so a line with no tax treatment at all is
 * expressible; the calculator treats null as exempt with no tax, and it is
 * `nullOnDelete` because losing the rate row must not take the invoice with
 * it — the charged figures are already frozen in the two money columns.
 *
 * `invoice_id` is `cascadeOnDelete` so orphaned lines are impossible, with
 * InvoiceObserver deleting them through Eloquent first so each still writes
 * an audit row. Same trap as journal lines and payslips.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_invoice_lines')) {
            return;
        }

        Schema::create('pas_invoice_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedInteger('line_number')->default(1);

            $table->string('description', 255);
            $table->decimal('quantity', 12, 4)->default(1);
            $table->bigInteger('unit_price_centavos')->default(0);

            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('tax_rate_id')->nullable();

            $table->bigInteger('line_net_centavos')->default(0);
            $table->bigInteger('line_tax_centavos')->default(0);

            $table->timestamps();

            $table->index(['invoice_id', 'line_number'], 'pas_invl_invoice_line_idx');
            // The income-by-account and expense-by-account reports walk lines
            // this way, mirroring pas_jel_school_account_idx.
            $table->index(['school_id', 'account_id'], 'pas_invl_school_account_idx');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();

            $table->foreign('invoice_id')
                ->references('id')
                ->on('pas_invoices')
                ->cascadeOnDelete();

            $table->foreign('account_id')
                ->references('id')
                ->on('pas_chart_of_accounts')
                ->restrictOnDelete();

            $table->foreign('tax_rate_id')
                ->references('id')
                ->on('pas_tax_rates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_invoice_lines');
    }
};
