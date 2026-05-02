<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting period primitive used for period locking. Once a period is
 * closed (status = 'closed'), no records owned by that period may be created,
 * edited, or voided. Corrections require a reversing entry in an open period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pas_accounting_periods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code')->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();

            $table->index('status', 'pas_accounting_periods_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_accounting_periods');
    }
};
