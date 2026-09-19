<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One allowance, whichever tab the contact came from.
 *
 * `grap_reveals` was written for the directory, where every unlock is a row
 * that already exists in `grap_leads`. Grap Company has no such row: it goes
 * out to the web, finds people who were not in any table an hour ago, and pays
 * a vendor to confirm their addresses.
 *
 * Both are the same thing from where the person sitting in front of it stands
 * — a verified address they did not have before — and Ayan asked for one
 * allowance covering both. So the receipt stops being "a lead you unlocked"
 * and becomes "a contact you unlocked": the lead is optional, the address is
 * not, and the count that gates the plan is one query over one table.
 *
 * The alternative was a second set of plan keys and a second counter, which
 * would have made "how many contacts do I have left today" a question with two
 * answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grap_reveals', function (Blueprint $table) {
            /*
             * Where it came from: 'directory' for Grap Buyer and Grap
             * Supplier, 'company' for a Grap Company search. Kept so the
             * ledger can be read per tab later without inferring it from
             * which column is null.
             */
            $table->string('source', 16)->default('directory')->after('grap_lead_id');

            /*
             * The address itself, for a company unlock that has no lead row to
             * point at. Null for a directory unlock, where the lead already
             * holds it.
             */
            $table->string('email', 191)->nullable()->after('source');

            // The company it was found at, so the row reads as something on
            // its own rather than only in reference to a lead.
            $table->string('company_name', 191)->nullable()->after('email');
        });

        /*
         * MySQL lets NULLs repeat in a unique index, so the existing
         * (user_id, grap_lead_id) key stops constraining company unlocks the
         * moment the lead is null — which is what we want, since a person may
         * unlock many contacts at many companies. Directory unlocks keep the
         * "never charge twice for the same lead" guarantee unchanged.
         */
        Schema::table('grap_reveals', function (Blueprint $table) {
            $table->foreignId('grap_lead_id')->nullable()->change();
            $table->index(['user_id', 'source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('grap_reveals', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'source', 'created_at']);
            $table->dropColumn(['source', 'email', 'company_name']);
        });
    }
};
