<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 1 — the per-school tax-rate catalog applied to invoice and
 * bill lines.
 *
 * `rate_bps` holds the rate in **basis points as an integer** — 12% VAT is
 * `1200`, not `0.12`. Same discipline as money-as-centavos: a float rate
 * multiplied against a centavo amount reintroduces exactly the drift the
 * Money value object exists to prevent. Callers compute tax as
 * `Money::times($rate_bps)->dividedBy(10_000)`, which keeps the whole
 * calculation in integer space with banker's rounding at the single
 * division.
 *
 * `type` drives which side of the ledger the tax posts to:
 *   - vat_sales    → output VAT, a liability (VAT collected, owed to BIR)
 *   - vat_purchase → input VAT, an asset (VAT paid, creditable against output)
 *   - exempt       → no tax line; accumulates into the VAT-exempt subtotal
 *   - zero_rated   → no tax line; accumulates into the zero-rated subtotal
 *
 * exempt and zero_rated both yield zero tax but are NOT interchangeable —
 * BIR requires them reported as separate subtotals on the sales invoice, so
 * they stay distinct types rather than collapsing into one "0%" rate.
 *
 * `account_id` is the chart-of-accounts row the tax posts to. Nullable
 * because exempt / zero_rated rates post nothing.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_tax_rates')) {
            return;
        }

        Schema::create('pas_tax_rates', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');
            $table->string('code', 32);
            $table->string('name', 120);
            $table->unsignedInteger('rate_bps')->default(0);
            $table->string('type', 16);
            $table->unsignedBigInteger('account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'code'], 'pas_tax_rates_school_code_unq');
            $table->index(['school_id', 'is_active'], 'pas_tax_rates_school_active_idx');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();

            $table->foreign('account_id')
                ->references('id')
                ->on('pas_chart_of_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_tax_rates');
    }
};
