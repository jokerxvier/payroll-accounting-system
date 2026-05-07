<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the four approval-lifecycle timestamps + actor FKs to `pas_payroll_runs`.
 * Pairs with Week 10's state machine:
 *
 *     computed → pending_approval → approved → posted
 *
 * `posted_at` is the immutability cliff — once set, the run is final and
 * cannot be voided or re-run. Each timestamp's matching `*_by_user_id` is
 * `unsignedInteger` (NOT bigint) to match the legacy LMS `users.id` column.
 *
 * Touches only `pas_payroll_runs` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pas_payroll_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_payroll_runs', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('computed_at');
            }
            if (! Schema::hasColumn('pas_payroll_runs', 'submitted_by_user_id')) {
                $table->unsignedInteger('submitted_by_user_id')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('pas_payroll_runs', 'posted_at')) {
                $table->timestamp('posted_at')->nullable()->after('approved_by_user_id');
            }
            if (! Schema::hasColumn('pas_payroll_runs', 'posted_by_user_id')) {
                $table->unsignedInteger('posted_by_user_id')->nullable()->after('posted_at');
            }
        });

        // FKs to LMS-owned `users` are guarded — the table may not exist on
        // bootstrap of a fresh environment.
        if (Schema::hasTable('users')) {
            Schema::table('pas_payroll_runs', function (Blueprint $table): void {
                $table->foreign('submitted_by_user_id')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('posted_by_user_id')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('pas_payroll_runs', function (Blueprint $table): void {
            if (Schema::hasTable('users')) {
                $table->dropForeign(['submitted_by_user_id']);
                $table->dropForeign(['posted_by_user_id']);
            }
            $table->dropColumn([
                'submitted_at',
                'submitted_by_user_id',
                'posted_at',
                'posted_by_user_id',
            ]);
        });
    }
};
