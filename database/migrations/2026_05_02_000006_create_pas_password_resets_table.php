<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App-owned password reset tokens table. The LMS users table is read-only at
 * the app layer, but Fortify's password reset flow needs somewhere to store
 * its tokens. This is the only auth-adjacent table this app owns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_password_resets')) {
            return;
        }

        Schema::create('pas_password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_password_resets');
    }
};
