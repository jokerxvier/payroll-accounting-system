<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school payment gateway credentials.
 *
 * Per-school rather than per-platform because each school is its own
 * BIR-registered entity with its own merchant account: the money must land in
 * that school's bank, and the school — not the platform — is the merchant of
 * record. This mirrors `pas_schools.lms_db_password`, which is the existing
 * precedent for a tenant holding its own third-party credential.
 *
 * **Test and live are separate rows**, keyed by `(school_id, provider, mode)`,
 * not one row with a mode flag. A single credential field with a toggle beside
 * it is how a mis-flip charges a real card from a test console; separate rows
 * make the live keys something you have to deliberately go and enter.
 *
 * `secret_key` and `webhook_secret` carry Laravel's `encrypted` cast on the
 * model, and the model's `auditExclude()` keeps both out of `pas_audit_logs` —
 * an audit row is a second copy of the credential in a table auditors can
 * export, which is exactly the reasoning behind `School::auditExclude()`.
 *
 * `webhook_secret` is not optional in practice: without it a webhook cannot
 * verify a signature, and an unverified webhook is an open endpoint that marks
 * invoices paid. It is nullable only so a row can be saved before the gateway
 * dashboard has issued one.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_payment_gateway_settings')) {
            return;
        }

        Schema::create('pas_payment_gateway_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');

            $table->string('provider', 32);
            $table->string('mode', 8);

            // Publishable keys are safe to ship to a browser; the other two
            // never leave the server.
            $table->string('publishable_key', 255)->nullable();
            $table->text('secret_key')->nullable();
            $table->text('webhook_secret')->nullable();

            // Where settled money lands, and where the merchant fee is
            // expensed. Both nullable so credentials can be entered before
            // the chart of accounts is finalised; posting refuses if unset.
            $table->unsignedBigInteger('cash_account_id')->nullable();
            $table->unsignedBigInteger('fee_account_id')->nullable();

            // At most one active row per provider per school — enforced in the
            // request, not the schema, since "exactly one of a filtered set"
            // is not something a unique index can express.
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            $table->unique(
                ['school_id', 'provider', 'mode'],
                'pas_gateway_settings_school_provider_mode_unq',
            );
            $table->index(['school_id', 'is_active'], 'pas_gateway_settings_school_active_idx');

            $table->foreign('school_id')
                ->references('id')
                ->on('pas_schools')
                ->restrictOnDelete();

            $table->foreign('cash_account_id')
                ->references('id')
                ->on('pas_chart_of_accounts')
                ->nullOnDelete();

            $table->foreign('fee_account_id')
                ->references('id')
                ->on('pas_chart_of_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_payment_gateway_settings');
    }
};
