<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pas_employee_profiles', function (Blueprint $table): void {
            $table->json('exempted_contribution_codes')->nullable()->after('pagibig_number');
        });
    }

    public function down(): void
    {
        Schema::table('pas_employee_profiles', function (Blueprint $table): void {
            $table->dropColumn('exempted_contribution_codes');
        });
    }
};
