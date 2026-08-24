<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 4 — the contact register: who an invoice or bill is addressed
 * to.
 *
 * One table for every audience, the way Xero models it: a contact carries an
 * `is_customer` flag, an `is_supplier` flag, or both. A parent billed for
 * tuition and a supplier who bills the school are the same shape of record,
 * and plenty of counterparties are genuinely both.
 *
 * A contact owns its own name, TIN, and address. `rules/PLAN.md` §2 restricts
 * LMS reads to `users`, `sm_staffs`, `sm_designations`,
 * `sm_human_departments`, and `roles` — student tables are not on that list,
 * and `sm_fees_*` is explicitly ignored. `lms_student_id` is therefore a bare
 * nullable integer with NO foreign key: storing an id reads nothing, and it
 * exists so that linking to LMS students later is an additive decision rather
 * than a migration. Nothing in this slice populates it.
 *
 * `tin` is unique per school when present. One TIN is one legal entity, and
 * duplicate contacts are the classic accounts-receivable data-quality
 * problem, so the constraint earns its keep. NULLs are distinct in a unique
 * index on both MySQL and sqlite, so any number of TIN-less contacts coexist.
 *
 * `receivable_account_id` / `payable_account_id` are OVERRIDES. Null means
 * "use this school's AR_CONTROL / AP_CONTROL system account", which is how
 * Slice 5 resolves them at posting time — the same `system_code` lookup
 * LedgerPostingService already uses for payroll clearing. Only a deliberate
 * departure from the default is stored here.
 *
 * Deliberately NOT cloned onto new schools by SchoolObserver. Allowances,
 * deduction types, the chart of accounts, and tax rates are catalog templates
 * worth copying. A customer list is real business data — copying one school's
 * customers onto another would be a data leak, not a convenience.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_contacts')) {
            return;
        }

        Schema::create('pas_contacts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');
            $table->string('code', 32);
            $table->string('name', 160);
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->string('tin', 32)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('receivable_account_id')->nullable();
            $table->unsignedBigInteger('payable_account_id')->nullable();
            // No FK: this points into the LMS database, a different
            // connection. Nothing in this slice writes it.
            $table->unsignedBigInteger('lms_student_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'code'], 'pas_contacts_school_code_unq');
            $table->unique(['school_id', 'tin'], 'pas_contacts_school_tin_unq');
            $table->index(['school_id', 'is_active'], 'pas_contacts_school_active_idx');
            // The list filters on these two flags constantly, and Slice 5's
            // pickers will filter to one or the other.
            $table->index(['school_id', 'is_customer'], 'pas_contacts_school_customer_idx');
            $table->index(['school_id', 'is_supplier'], 'pas_contacts_school_supplier_idx');
            $table->index(['school_id', 'name'], 'pas_contacts_school_name_idx');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();

            // restrictOnDelete: an account a contact posts through is part of
            // the ledger's wiring. ChartOfAccountController already
            // soft-blocks deleting an account something references.
            $table->foreign('receivable_account_id')
                ->references('id')
                ->on('pas_chart_of_accounts')
                ->restrictOnDelete();

            $table->foreign('payable_account_id')
                ->references('id')
                ->on('pas_chart_of_accounts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_contacts');
    }
};
