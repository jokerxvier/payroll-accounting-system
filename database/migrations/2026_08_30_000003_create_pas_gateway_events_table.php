<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every webhook a gateway has delivered, and what we did about it.
 *
 * Exists for one reason above all others: **idempotency**. Both PayMongo and
 * Stripe retry a delivery until they get a 2xx, and both can deliver the same
 * event more than once even after a success. Without a record of what has
 * already been handled, a retry records a second payment and the invoice is
 * paid twice. The unique on `(provider, external_event_id)` is the guarantee;
 * everything else here is diagnostics.
 *
 * `payload` is kept because a payment that posted from a webhook has no human
 * actor to ask. When a figure is questioned months later this is the only
 * record of what the gateway actually said.
 *
 * `school_id` is nullable on purpose: a delivery whose signature fails, or
 * whose slug resolves to nothing, still deserves a row. Those are precisely
 * the deliveries worth being able to look at afterwards, and refusing to
 * record them because they could not be attributed would discard the evidence
 * of an attack.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_gateway_events')) {
            return;
        }

        Schema::create('pas_gateway_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id')->nullable();

            $table->string('provider', 32);
            $table->string('external_event_id', 191);
            $table->string('event_type', 64)->nullable();

            // pending → handled | ignored | failed
            $table->string('status', 16)->default('pending');
            $table->text('message')->nullable();

            // The document and the receipt this event produced, when it
            // produced one. Null for an event we deliberately ignored.
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();

            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // The idempotency guarantee.
            $table->unique(
                ['provider', 'external_event_id'],
                'pas_gateway_events_provider_event_unq',
            );
            $table->index(['school_id', 'created_at'], 'pas_gateway_events_school_created_idx');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->nullOnDelete();

            $table->foreign('invoice_id')
                ->references('id')
                ->on('pas_invoices')
                ->nullOnDelete();

            $table->foreign('payment_id')
                ->references('id')
                ->on('pas_payments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_gateway_events');
    }
};
