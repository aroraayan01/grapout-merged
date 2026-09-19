<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GrapOut team can switch calls and meetings off for one account, or
 * for a whole company page (everybody on its team). Off is the exception:
 * both stay on unless somebody says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('calls_disabled')->default(false)->after('status');
            $table->boolean('meetings_disabled')->default(false)->after('calls_disabled');
        });
        Schema::table('business_pages', function (Blueprint $table) {
            $table->boolean('calls_disabled')->default(false)->after('show_whatsapp');
            $table->boolean('meetings_disabled')->default(false)->after('calls_disabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['calls_disabled', 'meetings_disabled']));
        Schema::table('business_pages', fn (Blueprint $t) => $t->dropColumn(['calls_disabled', 'meetings_disabled']));
    }
};
