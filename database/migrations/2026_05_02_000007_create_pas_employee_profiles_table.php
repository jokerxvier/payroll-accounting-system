<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll-specific profile data for an LMS staff record.
 *
 * Bridges to the read-only LMS sm_staffs table via lms_staff_id (= sm_staffs.id).
 * No FK constraint is declared on lms_staff_id because that column lives on the
 * read-only `lms` connection — even though it currently points at the same physical
 * MySQL DB, treating LMS tables as a foreign system keeps the boundary clean.
 *
 * Money is stored as integer centavos (basic_salary_centavos). Sensitive fields
 * (TIN, SSS, PhilHealth, Pag-IBIG, bank account number) are encrypted at the
 * model layer via Laravel's `encrypted` cast, so the column type is text/string
 * to accommodate the ciphertext payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pas_employee_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Bridge column — references sm_staffs.id on the lms connection.
            // No foreign key because the target lives on a different (read-only) connection.
            $table->unsignedBigInteger('lms_staff_id');

            // Employment classification — kept as a string column; enum constants live in code.
            // Allowed values: regular, probationary, contractual, part_time
            $table->string('employment_classification', 32)->default('regular');

            // Pay frequency — string column; allowed values: monthly, semi_monthly
            $table->string('pay_frequency', 16)->default('semi_monthly');

            // Basic monthly salary in integer centavos (PHP minor units).
            // Never store money as float or decimal — wrap with the Money value object at the service layer.
            $table->bigInteger('basic_salary_centavos')->default(0);

            // Tax status (S/M/ME/MEx codes — largely obsolete post-TRAIN, kept nullable for legacy mapping).
            $table->string('tax_status', 8)->nullable();

            // Sensitive fields — encrypted at rest via the `encrypted` cast on the model.
            // Stored as text because Laravel's encrypted payload is variable-length and exceeds
            // typical short-string lengths once base64+IV+MAC are appended.
            $table->text('tin')->nullable();
            $table->text('sss_number')->nullable();
            $table->text('philhealth_number')->nullable();
            $table->text('pagibig_number')->nullable();

            $table->string('bank_name', 100)->nullable();
            $table->text('bank_account_number')->nullable(); // encrypted
            $table->string('bank_account_name', 150)->nullable();

            $table->date('date_hired')->nullable();
            $table->date('date_terminated')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('lms_staff_id', 'pas_employee_profiles_lms_staff_id_unique');
            $table->index('is_active', 'pas_employee_profiles_is_active_idx');
            $table->index('employment_classification', 'pas_employee_profiles_classification_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pas_employee_profiles');
    }
};
