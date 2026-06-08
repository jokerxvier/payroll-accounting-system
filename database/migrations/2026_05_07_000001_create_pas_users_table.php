<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A.1 of the multi-tenant refactor.
 *
 * Establishes the payroll-DB-owned `pas_users` table that will eventually
 * own Fortify auth state. This migration only creates the table and its
 * indexes — auth flow swap, FK redirection (currently five FKs reference
 * the LMS `users.id`), and removal of the `LmsWriteException` allowlist
 * plumbing on App\Models\User happen in subsequent slices (A.2 / A.3 /
 * A.4) per docs/improvement/plan-2.md.
 *
 * id-preserving backfill note:
 * The companion BackfillPasUsersSeeder inserts rows with explicit ids
 * copied from LMS `users.id`. That keeps the eventual FK redirection in
 * Phase A.3 a pure connection swap with zero data migration — every
 * existing pas_audit_logs.user_id, pas_payroll_runs.{submitted_by,
 * approved_by, posted_by, voided_by} and pas_statutory_contributions.
 * voided_by reference will line up against the equivalent pas_users.id.
 *
 * Cross-reference columns:
 *   - lms_user_id (nullable) — points back at the LMS user. Nullable
 *     because future rows from non-LMS auth sources may not have one.
 *     No FK constraint because LMS lives on a different connection.
 *   - school_id (nullable) — populated in Phase B once `pas_schools`
 *     exists. No FK constraint yet; that ships with Phase B.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_users')) {
            return;
        }

        Schema::create('pas_users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('lms_user_id')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token', 100)->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();

            $table->unique('email', 'pas_users_email_uq');
            $table->index('lms_user_id', 'pas_users_lms_user_id_idx');
            $table->index('school_id', 'pas_users_school_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_users');
    }
};
