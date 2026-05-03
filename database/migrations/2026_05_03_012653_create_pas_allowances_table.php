<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog of allowance types (rice subsidy, transportation, meal, communication, etc.).
 *
 * One row per logical allowance type. Per-employee assignments live on
 * `pas_employee_allowances`. Money columns are integer centavos. The
 * de-minimis cap is nullable: NULL means "no cap" pending the BIR cap
 * specification (tracked as Q3 in rules/PLAN.md). Super-admin updates
 * the cap once the client supplies the figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pas_allowances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_de_minimis')->default(false);
            $table->bigInteger('de_minimis_cap_centavos')->nullable();
            $table->bigInteger('default_amount_centavos')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('is_active', 'pas_allowances_is_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_allowances');
    }
};
