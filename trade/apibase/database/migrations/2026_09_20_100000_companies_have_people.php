<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company page belongs to a company, and a company has people.
 *
 * Until now one person owned one page. Now a page has members — owner,
 * admins, representatives — each with the function they serve
 * (procurement, sales, ...), which is what lets the app say "the right
 * person to talk to". Every existing page's creator becomes its owner.
 *
 * The page also says what the company buys and sells, line by line, each
 * line keyed by an HS code: the language customs, buyers' procurement
 * systems and trade data already speak, and the key everything will be
 * matched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'representative'])->default('representative');
            $table->string('function', 24)->nullable();
            $table->string('title', 120)->nullable();
            // active: on the team. invited: asked by the company, not yet in.
            // requested: asked to join, not yet approved.
            $table->enum('status', ['active', 'invited', 'requested'])->default('active');
            $table->string('invited_email')->nullable();
            $table->string('invite_token', 64)->nullable()->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['page_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        // Every page so far had one person: its creator is now its owner.
        $now = now();
        foreach (DB::table('business_pages')->select('id', 'user_id', 'created_at')->get() as $page) {
            DB::table('company_members')->insert([
                'page_id' => $page->id, 'user_id' => $page->user_id, 'role' => 'owner', 'function' => 'management',
                'status' => 'active', 'joined_at' => $page->created_at ?? $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::create('company_trade_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->enum('direction', ['buy', 'sell']);
            $table->string('hs_code', 10)->nullable();
            $table->string('description', 200);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['page_id', 'direction']);
            $table->index('hs_code');
        });

        Schema::table('business_pages', function (Blueprint $table) {
            $table->json('markets')->nullable()->after('keywords');
            $table->json('certifications')->nullable()->after('markets');
            $table->unsignedSmallInteger('year_established')->nullable()->after('certifications');
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            // What the person does for the company: procurement, sales, ...
            $table->string('role_function', 24)->nullable()->after('industry');
        });

        // The HS nomenclature, loaded from a file the platform provides.
        Schema::create('hs_codes', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->text('description');
            $table->unsignedTinyInteger('level')->default(6);
            $table->string('parent', 10)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_codes');
        Schema::table('user_profiles', fn (Blueprint $t) => $t->dropColumn('role_function'));
        Schema::table('business_pages', fn (Blueprint $t) => $t->dropColumn(['markets', 'certifications', 'year_established']));
        Schema::dropIfExists('company_trade_lines');
        Schema::dropIfExists('company_members');
    }
};
