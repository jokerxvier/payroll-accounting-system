<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 5 — invoices. One table with a `type` discriminator covering
 * both a sales invoice issued to a customer and a purchase bill received
 * from a supplier.
 *
 * One table rather than two because the shape is genuinely identical: lines,
 * VAT treatment, and payment allocation behave the same on both sides. Only
 * the posting direction (receivable vs payable) and the numbering series
 * differ, and both of those are one branch each in the posting service.
 * Splitting them would duplicate the calculator, the line editor, and the
 * PDF for no modelling gain.
 *
 * The money columns mirror the face of a BIR sales invoice rather than a
 * generic subtotal, because those are the figures the form must print and
 * the returns must report separately:
 *
 *   vatable_sales_centavos     net of lines carrying a non-zero VAT rate
 *   vat_exempt_sales_centavos  net of `exempt` lines
 *   zero_rated_sales_centavos  net of `zero_rated` lines
 *   vat_centavos               the tax itself
 *   total_centavos             the three nets plus VAT
 *
 * Exempt and zero-rated are separate columns, not one "no tax" bucket: both
 * produce zero VAT but the BIR requires them reported on different lines, so
 * collapsing them would lose information that cannot be recovered later.
 *
 * `total_centavos = vatable + exempt + zero_rated + vat` is an invariant the
 * calculator maintains and the posting service asserts before it will build
 * a journal entry.
 *
 * `number` is nullable for the same reason `pas_journal_entries.entry_number`
 * is: a draft has no number. A BIR-controlled serial is allocated only at
 * approval, so an abandoned draft never burns one — see
 * App\Services\Accounting\DocumentNumberAllocator, which refuses to issue
 * outside a transaction precisely so a failed document returns its number.
 *
 * `is_vat_inclusive` records how the operator keyed the prices, not a
 * different kind of sale. The same sale keyed either way must reach the same
 * three buckets and the same VAT.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_invoices')) {
            return;
        }

        Schema::create('pas_invoices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');

            // 'sales' (AR, issued by us) | 'purchase' (AP, received by us).
            $table->string('type', 16)->default('sales');
            $table->unsignedBigInteger('contact_id');

            // Null until approved — see the class docblock.
            $table->string('number', 32)->nullable();
            // The counterparty's own document number on a purchase bill, or
            // a PO / student reference on a sales invoice. Free text: it is
            // someone else's numbering scheme, so we do not constrain it.
            $table->string('reference', 64)->nullable();

            $table->date('issue_date');
            $table->date('due_date')->nullable();

            $table->string('status', 24)->default('draft');
            $table->boolean('is_vat_inclusive')->default(false);

            $table->bigInteger('vatable_sales_centavos')->default(0);
            $table->bigInteger('vat_exempt_sales_centavos')->default(0);
            $table->bigInteger('zero_rated_sales_centavos')->default(0);
            $table->bigInteger('vat_centavos')->default(0);
            $table->bigInteger('total_centavos')->default(0);
            // Written by Slice 7 (payments). Kept here from the start so the
            // status machine has somewhere to read from rather than
            // aggregating allocations on every list render.
            $table->bigInteger('amount_paid_centavos')->default(0);

            $table->text('notes')->nullable();
            // Printed on the document face. Separate from `notes`, which is
            // internal: one is for the customer, the other is not.
            $table->text('terms')->nullable();

            $table->unsignedBigInteger('journal_entry_id')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            // A voided invoice keeps its number. The Bureau expects every
            // serial in an authorised range to be accounted for, including
            // the cancelled ones, so the number is never released for reuse.
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by_user_id')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(['school_id', 'type', 'number'], 'pas_inv_school_type_number_unq');
            $table->index(['school_id', 'type', 'status'], 'pas_inv_school_type_status_idx');
            $table->index(['school_id', 'contact_id'], 'pas_inv_school_contact_idx');
            // The AR/AP ageing reports walk unpaid documents by due date.
            $table->index(['school_id', 'due_date'], 'pas_inv_school_due_idx');
            $table->index(['school_id', 'issue_date'], 'pas_inv_school_issue_idx');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();

            // restrictOnDelete: a contact that has been invoiced is part of
            // the ledger's history. Deactivate it instead — that is what
            // `is_active` on pas_contacts is for.
            $table->foreign('contact_id')
                ->references('id')
                ->on('pas_contacts')
                ->restrictOnDelete();

            $table->foreign('journal_entry_id')
                ->references('id')
                ->on('pas_journal_entries')
                ->restrictOnDelete();
        });

        if (Schema::hasTable('pas_users')) {
            Schema::table('pas_invoices', function (Blueprint $table): void {
                $table->foreign('approved_by_user_id')
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
        Schema::dropIfExists('pas_invoices');
    }
};
