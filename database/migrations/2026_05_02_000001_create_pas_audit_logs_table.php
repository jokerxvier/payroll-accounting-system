<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every financial mutation across the app.
 *
 * Append-only — there is no updated_at on this table; rows are written once and
 * never modified. Actor links to the LMS users table by id but no FK constraint
 * is declared here because users lives on the read-only lms connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pas_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id'], 'pas_audit_logs_auditable_idx');
            $table->index('actor_id', 'pas_audit_logs_actor_id_idx');
            $table->index('created_at', 'pas_audit_logs_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_audit_logs');
    }
};
