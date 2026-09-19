<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a person has unlocked, and where they filed it.
 *
 * A search shows a company and hides how to reach it; asking for the
 * contact spends from the plan. `grap_reveals` is that receipt, and it is
 * unique per person and lead on purpose: the old site counted every look
 * at the same row, so returning to a contact you had already paid for
 * charged you twice. Once bought, always visible.
 *
 * `grap_lists` is the old My Contacts folder, minus its one real
 * limitation. There, a folder belonged to a contact *type* — a buyer
 * folder could not hold a supplier — so "Dubai trip, March" had to be
 * built twice and kept in step by hand. A list here holds either kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grap_reveals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grap_lead_id')->constrained('grap_leads')->cascadeOnDelete();

            // Which side was asked for: 'email', 'phone', or 'both'. A plan may
            // allow one and not the other, so the receipt has to say.
            $table->string('channels', 12)->default('both');

            // What it cost, in whatever the plan counts. Kept per row so a
            // later change of pricing does not rewrite history.
            $table->unsignedSmallInteger('credits')->default(1);

            $table->timestamps();

            $table->unique(['user_id', 'grap_lead_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('grap_lists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('grap_list_lead', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grap_list_id')->constrained('grap_lists')->cascadeOnDelete();
            $table->foreignId('grap_lead_id')->constrained('grap_leads')->cascadeOnDelete();
            // Why this one is on this list — the thing people write on a
            // sticky note and then lose.
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['grap_list_id', 'grap_lead_id']);
        });

        // Every search, so the daily and monthly allowances have something to
        // count and the person can see what they looked for.
        Schema::create('grap_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->index();
            $table->string('q', 255)->nullable();
            $table->json('filters')->nullable();
            /*
             * A hash of (kind, text, filters) — the search itself, without
             * which page.
             *
             * Page 2 of a search is not a second search, but it cannot simply
             * be exempted either: "give me page 2" would then be a way to
             * read the database without ever spending an allowance. Matching
             * on the signature says the difference precisely — a page of a
             * question already asked today is free; a question not asked
             * before is not.
             */
            $table->char('signature', 40)->index();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grap_searches');
        Schema::dropIfExists('grap_list_lead');
        Schema::dropIfExists('grap_lists');
        Schema::dropIfExists('grap_reveals');
    }
};
