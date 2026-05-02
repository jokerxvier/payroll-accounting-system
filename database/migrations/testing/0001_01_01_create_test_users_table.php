<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-scoped `users` migration.
 *
 * The production `users` table is owned by the LMS (read-only at the app
 * boundary). In tests we cannot use the live LMS schema — RefreshDatabase
 * would have to truncate it, which is forbidden by the LMS read-only
 * boundary AND would destroy production data. Instead, this migration
 * recreates a sqlite-compatible subset of the LMS users schema with only
 * the columns Fortify and the auth feature tests touch.
 *
 * This migration lives OUTSIDE database/migrations/ so MigrationSafetyTest
 * (which enforces the pas_ prefix on every file in that directory) does not
 * flag it. It is loaded explicitly by Tests\TestCase::setUp().
 *
 * The schema mirrors only what the auth surface needs:
 *   - id, name, email, password, remember_token (bcrypt auth)
 *   - email_verified_at (Fortify email verification)
 *   - role_id (LMS role mapping → Spatie payroll role on login)
 *   - timestamps
 *
 * The real LMS users table has 50+ additional columns; tests do not need them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token', 100)->nullable();
            // LMS role_id used by AssignPayrollRoleOnLogin to map to a payroll role.
            $table->unsignedBigInteger('role_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
