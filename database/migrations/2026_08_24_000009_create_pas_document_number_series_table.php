<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Slice 5 — controlled document numbering.
 *
 * One row per school per document type. `next_number` is the counter; the
 * allocator takes it under a row lock inside the caller's transaction, so two
 * concurrent invoices cannot receive the same number.
 *
 * Why this is stricter than the journal's numbering: a journal entry number
 * is an internal reference, and a gap in it is untidy. A BIR-controlled
 * document number is a legal serial — the Bureau issues an Authority To Print
 * covering a specific range, and a gap in an issued range is an audit
 * finding. So allocation happens inside the same transaction that inserts the
 * document, and a rollback returns the number rather than burning it.
 *
 * The ATP columns are deliberately nullable and unpopulated. Which documents
 * this school is registered to issue — a Sales Invoice, an Official Receipt,
 * or both — and the serial ranges the BIR granted are Open Question 1, still
 * with the client. Under RA 11976 (Ease of Paying Taxes) the Invoice replaced
 * the OR as the primary sales document and the OR became supplementary, but
 * which of those a given school actually holds a permit for is a fact about
 * that school, not something to guess in code. The mechanism is identical
 * either way; only the rows differ.
 *
 * `serial_start` / `serial_end` bound an ATP-issued range. When set, the
 * allocator refuses to issue past the end, because doing so would put
 * unauthorised numbers on real documents.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_document_number_series')) {
            return;
        }

        Schema::create('pas_document_number_series', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');
            // sales_invoice, official_receipt, credit_note, bill, …
            $table->string('document_type', 32);
            $table->string('label', 120);
            $table->string('prefix', 16)->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);

            // BIR Authority To Print. Null until the client supplies the
            // permit details — see the class docblock.
            $table->string('atp_number', 64)->nullable();
            $table->date('permit_issued_at')->nullable();
            $table->unsignedBigInteger('serial_start')->nullable();
            $table->unsignedBigInteger('serial_end')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One active series per document type per school. Enforced as a
            // plain unique on (school_id, document_type): switching to a new
            // ATP booklet means editing the row, not adding a second.
            $table->unique(['school_id', 'document_type'], 'pas_doc_series_school_type_unq');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_document_number_series');
    }
};
