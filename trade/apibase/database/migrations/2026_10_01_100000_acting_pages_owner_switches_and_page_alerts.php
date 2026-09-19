<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's own switches for calls and meetings (the team's locks stay
 * separate), and alerts that belong to a page as well as to a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_pages', function (Blueprint $table) {
            $table->boolean('accept_calls')->default(true)->after('meetings_disabled');
            $table->boolean('accept_meetings')->default(true)->after('accept_calls');
        });
        Schema::table('saved_searches', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('user_id')->constrained('business_pages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('saved_searches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('page_id');
        });
        Schema::table('business_pages', function (Blueprint $table) {
            $table->dropColumn(['accept_calls', 'accept_meetings']);
        });
    }
};
