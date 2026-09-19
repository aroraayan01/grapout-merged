<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products and opportunities speak HS.
 *
 * A product carries its HS code, capacity, certifications and the markets
 * it already ships to. A requirement grows into a trade intent with three
 * kinds — BUY, SELL, PARTNER — its HS code, how often, where from, where
 * to, on what payment terms, and which company posted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_products', function (Blueprint $table) {
            $table->string('hs_code', 10)->nullable()->index()->after('category');
            $table->string('capacity', 80)->nullable()->after('moq_unit');
            $table->json('certifications')->nullable()->after('specifications');
            $table->json('export_markets')->nullable()->after('certifications');
        });

        Schema::table('business_requirements', function (Blueprint $table) {
            $table->string('kind', 12)->default('buy')->index()->after('user_id');
            $table->foreignId('page_id')->nullable()->after('kind')->constrained('business_pages')->nullOnDelete();
            $table->string('hs_code', 10)->nullable()->index()->after('category');
            $table->string('frequency', 16)->nullable()->after('quantity_unit');
            $table->json('origin_countries')->nullable()->after('terms');
            $table->json('target_markets')->nullable()->after('origin_countries');
            $table->string('payment_terms', 80)->nullable()->after('target_markets');
            $table->string('partner_type', 24)->nullable()->after('payment_terms');
        });
    }

    public function down(): void
    {
        Schema::table('business_requirements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('page_id');
            $table->dropColumn(['kind', 'hs_code', 'frequency', 'origin_countries', 'target_markets', 'payment_terms', 'partner_type']);
        });
        Schema::table('business_products', function (Blueprint $table) {
            $table->dropColumn(['hs_code', 'capacity', 'certifications', 'export_markets']);
        });
    }
};
