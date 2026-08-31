<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The merchant fee a gateway deducted before settling.
 *
 * A ₱1,000 invoice paid online settles as ₱975 in the bank with ₱25 kept by
 * the gateway. `amount_centavos` stays the GROSS ₱1,000 — that is what the
 * customer paid and what settles the receivable — and this column carries the
 * ₱25 so the posting can split it:
 *
 *     Dr Cash 975 · Dr Merchant fees 25 · Cr AR 1,000
 *
 * Recording the net instead would leave the invoice at `partially_paid`
 * forever with ₱25 outstanding, and Aged Receivables would fill with
 * fee-sized residue that nobody can collect.
 *
 * Defaults to zero, so every manually keyed payment posts exactly as it does
 * today and `PaymentPostingService` only grows a branch rather than changing
 * behaviour.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_payments')) {
            return;
        }

        Schema::table('pas_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_payments', 'fee_centavos')) {
                $table->bigInteger('fee_centavos')->default(0)->after('allocated_centavos');
            }

            // Pinned on the payment rather than read back from the gateway
            // settings at posting time. Settings are editable and deletable;
            // a posted entry must stay reproducible, and a void-and-repost
            // months later must hit the same expense account it did before.
            if (! Schema::hasColumn('pas_payments', 'fee_account_id')) {
                $table->unsignedBigInteger('fee_account_id')->nullable()->after('fee_centavos');
                $table->foreign('fee_account_id')
                    ->references('id')
                    ->on('pas_chart_of_accounts')
                    ->restrictOnDelete();
            }

            // The gateway's own id for the payment, so a figure in the books
            // can be matched to a row in the gateway dashboard.
            if (! Schema::hasColumn('pas_payments', 'gateway_provider')) {
                $table->string('gateway_provider', 32)->nullable()->after('method');
            }

            if (! Schema::hasColumn('pas_payments', 'gateway_reference')) {
                $table->string('gateway_reference', 191)->nullable()->after('gateway_provider');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_payments')) {
            return;
        }

        Schema::table('pas_payments', function (Blueprint $table): void {
            if (Schema::hasColumn('pas_payments', 'fee_account_id')) {
                $table->dropForeign(['fee_account_id']);
            }

            foreach (['fee_centavos', 'fee_account_id', 'gateway_provider', 'gateway_reference'] as $column) {
                if (Schema::hasColumn('pas_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
