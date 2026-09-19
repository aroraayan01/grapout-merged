<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cap on how many people a single Grap Company search returns.
 *
 * Internal work often needs two names, not eight, and paying to verify the
 * other six is waste. The cap is the searcher's, set per request — so it is
 * part of the question the cache answers, not a filter on the answer. A search
 * for the top 2 and a search for the top 8 are genuinely different searches:
 * different spend, different result. They must not share a cache row, or the
 * first to run would hand its two people to everyone who asked for eight.
 *
 * `0` means "no cap" — the whole team, GrapUp's own behaviour. It is a real
 * value in the key rather than NULL because MySQL treats NULLs as distinct in
 * a unique index, which would let two uncapped searches for the same company
 * both insert and defeat the point of the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grap_search_cache', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_contacts')->default(0)->after('target_role');
        });

        Schema::table('grap_search_cache', function (Blueprint $table) {
            $table->dropUnique('grap_search_cache_question');
            $table->unique(
                ['company_name', 'country', 'target_role', 'max_contacts'],
                'grap_search_cache_question',
            );
        });
    }

    public function down(): void
    {
        Schema::table('grap_search_cache', function (Blueprint $table) {
            $table->dropUnique('grap_search_cache_question');
        });

        Schema::table('grap_search_cache', function (Blueprint $table) {
            $table->dropColumn('max_contacts');
            $table->unique(
                ['company_name', 'country', 'target_role'],
                'grap_search_cache_question',
            );
        });
    }
};
