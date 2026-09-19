<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other direction, the comparable offer, the reasons to trust, and
 * the words in the reader's language.
 *
 * Requirements: a buyer says what they need; suppliers whose keywords
 * match are told. Quotes: a structured answer — price on its terms,
 * minimum, lead time, validity — to a requirement or to an enquiry, so
 * offers line up in a table instead of a chat. Trust: a verified badge
 * the platform grants after seeing a document, and response figures
 * computed from how a page actually answers. Translations: a cache, so
 * the same sentence is bought from the provider once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_requirements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description');
            $table->string('category', 80)->nullable();
            $table->json('keywords')->nullable();
            $table->decimal('quantity', 14, 2)->nullable();
            $table->string('quantity_unit', 24)->nullable();
            $table->decimal('target_price', 14, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->json('terms')->nullable();
            $table->char('destination_country', 2)->nullable();
            $table->string('destination_port', 80)->nullable();
            $table->date('valid_until')->nullable();
            $table->enum('status', ['open', 'closed', 'fulfilled'])->default('open');
            $table->unsignedInteger('quotes_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('user_id');
        });

        Schema::create('business_quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requirement_id')->nullable()->constrained('business_requirements')->cascadeOnDelete();
            $table->foreignId('enquiry_id')->nullable()->constrained('business_enquiries')->cascadeOnDelete();
            // The buyer. Null while a guest enquiry is unclaimed.
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('price', 14, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('price_unit', 40)->nullable();
            $table->decimal('moq', 14, 2)->nullable();
            $table->string('moq_unit', 24)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->unsignedSmallInteger('valid_days')->default(30);
            $table->string('terms', 8)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['sent', 'accepted', 'declined', 'withdrawn'])->default('sent');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['requirement_id', 'status']);
            $table->index(['enquiry_id']);
            $table->index(['page_id', 'status']);
            $table->index(['buyer_user_id', 'status']);
        });

        Schema::table('business_pages', function (Blueprint $table) {
            $table->enum('verification_status', ['none', 'pending', 'verified', 'rejected'])->default('none')->after('status');
            $table->string('verification_kind', 24)->nullable()->after('verification_status');
            $table->string('verification_number', 80)->nullable()->after('verification_kind');
            $table->string('verification_document_path')->nullable()->after('verification_number');
            $table->text('verification_note')->nullable()->after('verification_document_path');
            $table->timestamp('verification_requested_at')->nullable()->after('verification_note');
            $table->timestamp('verified_at')->nullable()->after('verification_requested_at');
            // How the page answers, recomputed whenever it does.
            $table->unsignedTinyInteger('response_rate')->nullable()->after('verified_at');
            $table->decimal('response_hours', 8, 1)->nullable()->after('response_rate');
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->char('hash', 40)->unique();
            $table->string('provider', 16);
            $table->string('target', 8);
            $table->string('source', 8)->nullable();
            $table->text('text');
            $table->text('translated');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
        Schema::table('business_pages', function (Blueprint $table) {
            $table->dropColumn(['verification_status', 'verification_kind', 'verification_number', 'verification_document_path', 'verification_note', 'verification_requested_at', 'verified_at', 'response_rate', 'response_hours']);
        });
        Schema::dropIfExists('business_quotes');
        Schema::dropIfExists('business_requirements');
    }
};
