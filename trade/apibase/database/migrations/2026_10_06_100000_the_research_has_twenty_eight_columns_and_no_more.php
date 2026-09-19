<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The export settles, and the table follows it.
 *
 * `grap_leads` was built from the old `gptdirectbuyerhead` — HS codes,
 * shipment dates, the exporter on the other end, USD values, a data
 * rating. The research is not delivered that way any more: it is 28
 * columns describing a company and up to two people at it, and that is
 * all there will be.
 *
 * So the columns nothing will ever fill go. Keeping them would be cheap
 * in storage and expensive everywhere else — a results table with a
 * permanently empty "Contact Type" column, a star rating that is always
 * zero, an HS code that never prints. A column that is always null is a
 * promise the data cannot keep.
 *
 * Buyer and supplier stay as `kind`: the same 28 columns arrive in two
 * files, and which file a row came from is the only thing separating
 * them. `counterparty` went with the rest, because without a shipment
 * there is no other end to name.
 *
 * If the trade columns ever come back, this is one migration in reverse —
 * `down()` restores them, empty.
 */
return new class extends Migration
{
    /** Columns the 28 do not cover. */
    private const GONE = [
        'product_description', 'hs_code', 'hs_code_4digit', 'hs_code_description',
        'email_status', 'email_2_status', 'contact_type',
        'counterparty', 'counterparty_country',
        'shipment_type', 'shipment_date', 'shipment_date_from', 'shipment_date_to',
        'month', 'year', 'value_import_usd', 'value_export_usd', 'rating',
    ];

    public function up(): void
    {
        $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        if ($mysql) {
            Schema::table('grap_leads', function (Blueprint $table) {
                // The full-text index names four columns that are about to
                // stop existing; it has to go before they do.
                $table->dropFullText('grap_leads_fulltext');
            });
        }

        /*
         * And so do the ordinary indexes, on every driver.
         *
         * SQLite refuses to drop a column an index still mentions — "error
         * in index grap_leads_hs_code_index after drop column" — and MySQL
         * would silently keep a composite one half-alive. Dropping them by
         * name first makes the two behave the same.
         */
        Schema::table('grap_leads', function (Blueprint $table) {
            $table->dropIndex('grap_leads_kind_rating_index');
            foreach (['hs_code', 'hs_code_4digit', 'email_status', 'year', 'rating'] as $column) {
                $table->dropIndex("grap_leads_{$column}_index");
            }
        });

        Schema::table('grap_leads', function (Blueprint $table) use ($mysql) {
            $table->dropColumn(self::GONE);

            // --- What the 28 carry that the table did not ----------------
            // The term the research was searching for when it found this
            // company. Empty in every sample row so far, kept because the
            // export has the column and a silently dropped column is worse
            // than an empty one.
            $table->string('input_name', 255)->nullable()->after('kind');
            // The company's id wherever the research keeps its own list.
            $table->string('company_ref', 60)->nullable()->after('company_name');
            // The export's own bookkeeping, which is not ours: our
            // created_at is when we imported the row, these are when the
            // research last touched it.
            $table->string('source_created_by', 120)->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->string('source_updated_by', 120)->nullable();
            $table->timestamp('source_updated_at')->nullable();

            if ($mysql) {
                $table->fullText(
                    ['company_name', 'contact_person', 'business_category', 'brief_intro', 'country'],
                    'grap_leads_fulltext'
                );
            }
        });
    }

    public function down(): void
    {
        $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        if ($mysql) {
            Schema::table('grap_leads', function (Blueprint $table) {
                $table->dropFullText('grap_leads_fulltext');
            });
        }

        Schema::table('grap_leads', function (Blueprint $table) use ($mysql) {
            $table->dropColumn(['input_name', 'company_ref', 'source_created_by', 'source_created_at', 'source_updated_by', 'source_updated_at']);

            $table->text('product_description')->nullable();
            $table->string('hs_code', 20)->nullable()->index();
            $table->string('hs_code_4digit', 8)->nullable()->index();
            $table->string('hs_code_description', 255)->nullable();
            $table->string('email_status', 24)->nullable()->index();
            $table->string('email_2_status', 24)->nullable();
            $table->string('contact_type', 60)->nullable();
            $table->string('counterparty', 255)->nullable();
            $table->string('counterparty_country', 120)->nullable();
            $table->string('shipment_type', 60)->nullable();
            $table->date('shipment_date')->nullable();
            $table->date('shipment_date_from')->nullable();
            $table->date('shipment_date_to')->nullable();
            $table->string('month', 20)->nullable();
            $table->smallInteger('year')->nullable()->index();
            $table->decimal('value_import_usd', 18, 2)->nullable();
            $table->decimal('value_export_usd', 18, 2)->nullable();
            $table->unsignedTinyInteger('rating')->default(0)->index();

            if ($mysql) {
                $table->index(['kind', 'rating']);
                $table->fullText(
                    ['company_name', 'contact_person', 'product_description', 'business_category', 'hs_code_description', 'country'],
                    'grap_leads_fulltext'
                );
            }
        });
    }
};
