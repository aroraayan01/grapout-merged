<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Showing an email and phone, or a WhatsApp button, on a public page is
 * something the GrapOut team allows per company first; only then can the
 * owner switch it on. Until then every conversation starts with an
 * enquiry, on the platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_pages', function (Blueprint $table) {
            $table->boolean('contacts_allowed')->default(false)->after('show_whatsapp');
            $table->boolean('whatsapp_allowed')->default(false)->after('contacts_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('business_pages', fn (Blueprint $t) => $t->dropColumn(['contacts_allowed', 'whatsapp_allowed']));
    }
};
