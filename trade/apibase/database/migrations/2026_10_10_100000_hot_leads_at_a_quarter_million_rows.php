<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hot Leads was built and tested on a few dozen sample rows. The real
 * research is a quarter of a million, and at that size the list stopped
 * being instant: opening a tab took three seconds and a search took three
 * more, because two things the query does have no index to lean on.
 *
 *  - **The order.** `bestFirst` ranks a row by what it can be reached at —
 *    a mobile beats a phone beats an email beats a name — expressed as a
 *    stack of CASE expressions. No index answers "ORDER BY a calculation",
 *    so every page sorted all 140k rows of a kind by hand. Precompute the
 *    rank into a column and index it, and the sort becomes a range read.
 *    The same number sends the unreachable rows to the bottom for free.
 *
 *  - **The search.** `LIKE '%term%'` has a leading wildcard, which no
 *    B-tree can start from, so every search scanned the whole table. The
 *    full-text index built back in migration six answers the same question
 *    from an index; this only had to switch the query to use it, but the
 *    index needs to exist, which on a fresh database it now will.
 *
 * `sort_rank` is a virtual generated column — computed on read, stored in
 * the index, costing nothing in the row. `0` is the most reachable, `11`
 * the least. Its formula is `bestFirst`'s three tiers packed into one
 * number: reach*4 + has-email*2 + has-name, so ordering by it alone
 * reproduces the old three-key sort exactly.
 */
return new class extends Migration
{
    private const RANK_EXPR =
        "(CASE WHEN COALESCE(mobile, '') <> '' THEN 0 WHEN COALESCE(phone, '') <> '' THEN 1 ELSE 2 END) * 4"
        . " + (CASE WHEN COALESCE(email, '') <> '' THEN 0 ELSE 1 END) * 2"
        . " + (CASE WHEN COALESCE(contact_person, '') <> '' THEN 0 ELSE 1 END)";

    public function up(): void
    {
        Schema::table('grap_leads', function (Blueprint $table) {
            $table->unsignedTinyInteger('sort_rank')->virtualAs(self::RANK_EXPR);
        });

        // kind narrows, sort_rank orders, id breaks ties newest-first — the
        // exact shape of "this tab's list, best rows first". The `id DESC` in
        // the index matters: the list orders id descending, and an ascending
        // index cannot serve a descending sort, so a plain index would still
        // filesort. Raw SQL because the schema builder has no way to say which
        // way a key part runs; MySQL 8, MariaDB 10.8+ and SQLite all accept it.
        DB::statement('CREATE INDEX grap_leads_kind_rank ON grap_leads (kind, sort_rank, id DESC)');
    }

    public function down(): void
    {
        Schema::table('grap_leads', function (Blueprint $table) {
            $table->dropIndex('grap_leads_kind_rank');
        });

        Schema::table('grap_leads', function (Blueprint $table) {
            $table->dropColumn('sort_rank');
        });
    }
};
