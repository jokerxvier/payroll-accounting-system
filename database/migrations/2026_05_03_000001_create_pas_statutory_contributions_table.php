<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pas_statutory_contributions')) {
            return;
        }

        Schema::create('pas_statutory_contributions', function (Blueprint $table) {
            $table->id();
            $table->string('contribution_code', 32);
            $table->string('label', 120);
            $table->string('algorithm', 32);
            $table->date('effective_from')->index();
            $table->date('effective_to')->nullable()->index();
            $table->json('rules');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Explicit index names — Laravel's auto-generated name
            // `pas_statutory_contributions_contribution_code_effective_from_index`
            // exceeds MySQL's 64-character identifier limit.
            $table->index(
                ['contribution_code', 'effective_from'],
                'pas_statutory_contributions_code_eff_from_idx',
            );
            $table->index(
                ['effective_from', 'effective_to'],
                'pas_statutory_contributions_eff_dates_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_statutory_contributions');
    }
};
