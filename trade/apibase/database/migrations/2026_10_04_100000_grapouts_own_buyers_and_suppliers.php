<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GrapOut's research, as rows people can search.
 *
 * The old grapout.com kept this in two near-identical tables,
 * `gptdirectbuyerhead` and `gptdirectsupplierhead`, whose columns differed
 * only in which side of the shipment they named: a buyer row carried its
 * exporter, a supplier row carried its importer. One table with a `kind`
 * says the same thing without doubling every index, every query and every
 * screen — and leaves room for the third kind that is coming.
 *
 * Two contacts per company, because the source data has two: a row is a
 * company as met through a person, and the second person is how you reach
 * the company when the first has left.
 *
 * Nothing here is written by hand. Rows arrive through `grap:import`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grap_leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // 'buyer' or 'supplier'; 'company' is reserved for the third kind.
            $table->string('kind', 12)->index();

            // --- The company ------------------------------------------------
            $table->string('company_name', 255)->index();
            $table->text('company_address')->nullable();
            $table->string('country', 120)->nullable()->index();
            $table->string('website', 255)->nullable();
            $table->string('linkedin_url', 255)->nullable();
            $table->text('brief_intro')->nullable();
            $table->string('business_category', 255)->nullable()->index();

            // --- What it trades ---------------------------------------------
            $table->text('product_description')->nullable();
            $table->string('hs_code', 20)->nullable()->index();
            $table->string('hs_code_4digit', 8)->nullable()->index();
            $table->string('hs_code_description', 255)->nullable();

            // --- The first contact ------------------------------------------
            $table->string('contact_person', 190)->nullable()->index();
            $table->string('designation', 190)->nullable()->index();
            $table->string('email', 190)->nullable();
            // Clearout's verdict, carried over as-is: valid, invalid, catch_all,
            // unknown. The old site refused to show anything but the first two.
            $table->string('email_status', 24)->nullable()->index();
            $table->boolean('email_catch_all')->default(false);
            // Sits beside the phone in the source. Assumed to be the dial code;
            // confirm against the real dump before relying on it.
            $table->string('dial_code', 12)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('mobile', 60)->nullable();

            // --- The second contact -----------------------------------------
            $table->string('contact_person_2', 190)->nullable();
            $table->string('designation_2', 190)->nullable();
            $table->string('email_2', 190)->nullable();
            $table->string('email_2_status', 24)->nullable();
            $table->boolean('email_2_catch_all')->default(false);
            $table->string('dial_code_2', 12)->nullable();
            $table->string('phone_2', 60)->nullable();
            $table->string('mobile_2', 60)->nullable();

            $table->string('contact_type', 60)->nullable();

            // --- The other side of the shipment -----------------------------
            // A buyer row names its exporter and where the goods came from; a
            // supplier row names its importer and where they went. Same two
            // columns, read according to `kind`.
            $table->string('counterparty', 255)->nullable();
            $table->string('counterparty_country', 120)->nullable();

            // --- The shipment itself ----------------------------------------
            $table->string('shipment_type', 60)->nullable();
            $table->date('shipment_date')->nullable();
            $table->date('shipment_date_from')->nullable();
            $table->date('shipment_date_to')->nullable();
            $table->string('month', 20)->nullable();
            $table->smallInteger('year')->nullable()->index();
            $table->decimal('value_import_usd', 18, 2)->nullable();
            $table->decimal('value_export_usd', 18, 2)->nullable();

            // --- How much to trust the row ----------------------------------
            $table->unsignedTinyInteger('rating')->default(0)->index();
            $table->string('data_source', 120)->nullable()->index();
            // The id this row had wherever it came from, so a re-import
            // updates a row rather than duplicating it.
            $table->string('source_ref', 120)->nullable();

            $table->timestamps();

            $table->unique(['data_source', 'source_ref'], 'grap_leads_source_unique');
            $table->index(['kind', 'country']);
            $table->index(['kind', 'rating']);
        });

        /*
         * Full-text over everything the old site searched with six ORed LIKEs.
         *
         * NOT YET USED. `Lead::scopeMatching` still runs LIKE '%term%', which
         * cannot use this index — or any index — so on the real tables it is a
         * full scan. The index is created now because it has to exist before
         * the query can be rewritten to MATCH ... AGAINST, and building it on
         * millions of rows later is an outage rather than a migration.
         *
         * Until that rewrite, search will be slow at production volume. It is
         * fine on the sample data, and it was no better on the old site.
         *
         * MySQL/MariaDB only; SQLite (the test suite) skips it.
         */
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('grap_leads', function (Blueprint $table) {
                $table->fullText([
                    'company_name', 'contact_person', 'product_description',
                    'business_category', 'hs_code_description', 'country',
                ], 'grap_leads_fulltext');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grap_leads');
    }
};
