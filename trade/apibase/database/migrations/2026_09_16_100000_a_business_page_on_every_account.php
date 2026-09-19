<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Netvork Trade — not a second app, an upgrade to the account you have.
 *
 * Somebody registers as themselves, the way everybody does. Then, when they
 * want to, they say what they do ("Importer of brass & handicrafts"), open a
 * business page beside their profile, put products on it, and start being
 * found by it. Nothing about the account changes hands; the same person,
 * the same connections, the same chat — with a company behind them now.
 *
 * Everything here hangs off users. There is no separate tenant, no separate
 * login, and the CRM addon is not touched: an employee of a CRM company may
 * also run a page of their own, and the two never meet.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Who you are, professionally — on the profile everybody already
         * has. These are what the network search reads, so a person with no
         * page at all is still findable as "importer of handicrafts".
         */
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('headline', 160)->nullable()->after('bio');
            $table->string('designation', 120)->nullable()->after('headline');
            $table->string('company_name', 160)->nullable()->after('designation');
            $table->string('industry', 120)->nullable()->after('company_name');
            $table->json('keywords')->nullable()->after('industry');
        });

        Schema::create('business_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // One page per person. A page is the person's company face;
            // somebody with two companies has two faces, and that is a
            // different product.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 160)->unique();
            $table->string('name', 160);
            $table->string('tagline', 200)->nullable();
            $table->text('about')->nullable();
            $table->string('kind', 32)->default('exporter');
            $table->char('country', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('website', 200)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            // The picture across the top of the owner's dashboard — any of
            // their product photos, chosen from the products section.
            $table->string('hero_path')->nullable();
            $table->json('keywords')->nullable();
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->unsignedInteger('followers_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('business_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['page_id', 'user_id']);
        });

        Schema::create('business_products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->string('slug', 180)->unique();
            $table->enum('kind', ['product', 'service'])->default('product');
            $table->string('name', 160);
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable();
            // [{label, value}] — a spec sheet nobody has to format.
            $table->json('specifications')->nullable();
            $table->decimal('moq', 14, 2)->nullable();
            $table->string('moq_unit', 40)->nullable();
            $table->enum('price_type', ['fixed', 'range', 'on_request'])->default('on_request');
            $table->decimal('price_min', 14, 2)->nullable();
            $table->decimal('price_max', 14, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('price_unit', 40)->nullable();
            // Incoterms the price is quoted on: FOB, CIF, EXW, …
            $table->json('terms')->nullable();
            $table->string('category', 120)->nullable();
            $table->json('keywords')->nullable();
            $table->enum('status', ['active', 'hidden'])->default('active');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedInteger('interested_count')->default(0);
            $table->unsignedInteger('enquiry_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['page_id', 'status']);
        });

        Schema::create('business_product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('business_products')->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'sort']);
        });

        // "Interested" — one tap, no words. Counted on the product, and the
        // owner is told who.
        Schema::create('business_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('business_products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'user_id']);
        });

        Schema::create('business_enquiries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('business_products')->nullOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->string('quantity', 80)->nullable();
            $table->decimal('target_price', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->enum('status', ['new', 'replied', 'closed'])->default('new');
            // The chat either side opened from it, once one of them did.
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['page_id', 'status']);
            $table->index('from_user_id');
        });

        /*
         * Posts. By a person, or by a person as their page — the page's
         * followers see the latter, the person's connections see both.
         */
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('business_pages')->nullOnDelete();
            $table->text('body')->nullable();
            $table->json('images')->nullable();
            // A share: this post says "look at that one", with or without words.
            $table->foreignId('repost_of_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('reposts_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'created_at']);
            $table->index(['page_id', 'created_at']);
        });

        Schema::create('post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['post_id', 'user_id']);
        });

        Schema::create('post_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index('post_id');
        });

        /*
         * Jobs. Built in full and switched off by default — the sidebar
         * entry appears only when the platform turns it on (AppSetting
         * jobs_enabled), so the module is there the day it is wanted.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('post_comments');
        Schema::dropIfExists('post_likes');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('business_enquiries');
        Schema::dropIfExists('business_interests');
        Schema::dropIfExists('business_product_images');
        Schema::dropIfExists('business_products');
        Schema::dropIfExists('business_follows');
        Schema::dropIfExists('business_pages');
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(['headline', 'designation', 'company_name', 'industry', 'keywords']);
        });
    }
};
