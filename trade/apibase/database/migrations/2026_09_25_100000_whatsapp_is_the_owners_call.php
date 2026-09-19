<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WhatsApp share on a company page is the owner's call, off by default:
 * a page that does not want conversations leaving the platform shows none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_pages', fn (Blueprint $t) => $t->boolean('show_whatsapp')->default(false)->after('show_contacts'));
    }

    public function down(): void
    {
        Schema::table('business_pages', fn (Blueprint $t) => $t->dropColumn('show_whatsapp'));
    }
};
