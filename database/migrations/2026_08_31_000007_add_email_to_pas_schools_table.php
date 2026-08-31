<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school office's own email address.
 *
 * Sits beside `registered_name`, `tin` and `business_address` as one more
 * letterhead fact the school maintains about itself — not an identity the app
 * authenticates anybody with, and deliberately unrelated to `pas_users.email`.
 *
 * It exists because outbound mail had no way to point a reply anywhere useful.
 * The invoice email tells a parent to "reply to this message and the school
 * office will sort it out", and until now that reply went to the platform's
 * `MAIL_FROM_ADDRESS` — a mailbox nobody at the school reads. This is the
 * address `Reply-To` carries.
 *
 * Note what it is **not**: the address mail is sent *from*. That stays the
 * authenticated sender, because sending as a school's own domain without an
 * SPF record authorising this host puts every invoice in a spam folder.
 *
 * 160 characters, matching `pas_contacts.email`. Nullable — a school that has
 * not filled it in simply gets no Reply-To, which is today's behaviour.
 *
 * Touches only `pas_*` — guardrail compliant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pas_schools')) {
            return;
        }

        Schema::table('pas_schools', function (Blueprint $table): void {
            if (! Schema::hasColumn('pas_schools', 'email')) {
                $table->string('email', 160)->nullable()->after('business_address');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pas_schools')) {
            return;
        }

        Schema::table('pas_schools', function (Blueprint $table): void {
            if (Schema::hasColumn('pas_schools', 'email')) {
                $table->dropColumn('email');
            }
        });
    }
};
