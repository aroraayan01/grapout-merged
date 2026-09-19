<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companies that exist before their people arrive.
 *
 * GrapOut's research already knows thousands of buyers and suppliers. Each
 * becomes a page — name, country, what it buys and sells — marked as
 * seeded and unclaimed. Enquiries to it go to the company by email with
 * a link to claim the page; whoever claims it, by a matching email domain
 * or by a super admin's approval, becomes its owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_pages', function (Blueprint $table) {
            // One person owned one page; now one admin account seeds thousands.
            // Ownership is the team table's business since Phase 1.
            // MySQL will not drop an index a foreign key stands on, so the
            // plain index goes in first and the unique one comes off after.
            $table->index('user_id', 'business_pages_user_id_index');
            $table->dropUnique(['user_id']);
            // Where the row came from ("grapout-2026-09.csv"), and its id there.
            $table->string('seeded_source', 80)->nullable()->after('status');
            $table->string('seeded_ref', 80)->nullable()->after('seeded_source');
            $table->timestamp('claimed_at')->nullable()->after('seeded_ref');
            $table->index(['seeded_source', 'seeded_ref']);
        });

        Schema::table('company_members', function (Blueprint $table) {
            // A request to own a seeded page, as against a request to join a team.
            $table->boolean('is_claim')->default(false)->after('status');
            $table->text('note')->nullable()->after('is_claim');
        });
    }

    public function down(): void
    {
        Schema::table('company_members', fn (Blueprint $t) => $t->dropColumn(['is_claim', 'note']));
        Schema::table('business_pages', function (Blueprint $table) {
            $table->dropIndex(['seeded_source', 'seeded_ref']);
            $table->dropColumn(['seeded_source', 'seeded_ref', 'claimed_at']);
            $table->unique('user_id');
            $table->dropIndex('business_pages_user_id_index');
        });
    }
};
