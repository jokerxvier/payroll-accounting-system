<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 7 — money in and money out.
 *
 * One table with a `type` discriminator (`receipt` | `disbursement`),
 * mirroring how `pas_invoices` covers both directions. The allocation
 * mechanics, the status machine, and the void path are identical on both
 * sides; only the posting direction and which control account is touched
 * differ.
 *
 * **There is no `number` column, deliberately.** A payment records money
 * moving, not a document issued. Drawing a serial from the `official_receipt`
 * series would make every receipt a BIR document — and which schools may
 * legally issue an OR is an open question with the client, while an issued
 * serial cannot be un-issued. `reference` is free text for whatever the
 * operator actually has: a cheque number, a bank reference, or the number on
 * a hand-written receipt. When official receipts are built, an OR becomes a
 * document issued *for* a payment, which is additive.
 *
 * `allocated_centavos` is denormalised so the list and the ageing reports do
 * not aggregate `pas_payment_allocations` on every render — the same
 * reasoning as the totals on `pas_invoices` and `pas_payroll_runs`. It is
 * written by ApplyPaymentAllocations and by nothing else.
 *
 * `amount_centavos - allocated_centavos` is the advance: money received that
 * no invoice has claimed. It posts to its own account rather than pushing the
 * receivable negative.
 *
 * `cash_account_id` is which bank or cash account the money actually moved
 * through, chosen per payment — a school with several accounts has to be able
 * to say which one, and guessing would put real cash in the wrong place.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_payments')) {
            return;
        }

        Schema::create('pas_payments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');

            // 'receipt' (money in, against sales invoices)
            // 'disbursement' (money out, against supplier bills)
            $table->string('type', 16)->default('receipt');
            $table->unsignedBigInteger('contact_id');

            $table->date('payment_date');
            $table->bigInteger('amount_centavos')->default(0);
            $table->bigInteger('allocated_centavos')->default(0);

            $table->unsignedBigInteger('cash_account_id');
            $table->string('method', 24)->default('cash');
            $table->string('reference', 64)->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('journal_entry_id')->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by_user_id')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['school_id', 'type', 'status'], 'pas_pay_school_type_status_idx');
            $table->index(['school_id', 'contact_id'], 'pas_pay_school_contact_idx');
            $table->index(['school_id', 'payment_date'], 'pas_pay_school_date_idx');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();

            // restrictOnDelete: a contact that has paid is part of the
            // ledger's history. Deactivate it instead.
            $table->foreign('contact_id')
                ->references('id')
                ->on('pas_contacts')
                ->restrictOnDelete();

            $table->foreign('cash_account_id')
                ->references('id')
                ->on('pas_chart_of_accounts')
                ->restrictOnDelete();

            $table->foreign('journal_entry_id')
                ->references('id')
                ->on('pas_journal_entries')
                ->restrictOnDelete();
        });

        if (Schema::hasTable('pas_users')) {
            Schema::table('pas_payments', function (Blueprint $table): void {
                $table->foreign('posted_by_user_id')
                    ->references('id')
                    ->on('pas_users')
                    ->nullOnDelete();

                $table->foreign('voided_by_user_id')
                    ->references('id')
                    ->on('pas_users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_payments');
    }
};
