<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who comes first in a search, and how many connection requests a day. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_pages', function (Blueprint $table) {
            $table->unsignedSmallInteger('rank_boost')->default(0)->after('followers_count');
            $table->unsignedSmallInteger('activity_score')->default(0)->after('rank_boost');
            $table->unsignedInteger('rank_score')->default(0)->index()->after('activity_score');
            $table->date('last_activity_at')->nullable()->after('rank_score');
        });
        Schema::create('business_page_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->date('day');
            $table->unique(['page_id', 'day']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('connection_daily_limit')->nullable()->after('meetings_disabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('connection_daily_limit'));
        Schema::dropIfExists('business_page_activity');
        Schema::table('business_pages', fn (Blueprint $t) => $t->dropColumn(['rank_boost', 'activity_score', 'rank_score', 'last_activity_at']));
    }
};
