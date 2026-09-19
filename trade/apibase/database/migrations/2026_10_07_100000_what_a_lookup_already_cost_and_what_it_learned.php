<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The four tables that keep Grap Company from paying twice.
 *
 * Verification is the only step in the pipeline that costs money, so the whole
 * design is "never spend a credit to learn something already known, and never
 * spend one to learn nothing at all". These tables are the memory that makes
 * that possible. Ported from GrapUp.
 *
 *  - `grap_verification_cache` — one verdict per address, forever until the
 *    TTL. An address checked before costs nothing.
 *  - `grap_domain_facts` — what a whole domain taught us: whether it can
 *    receive mail at all (free, DNS), whether it accepts everything, and the
 *    naming pattern it uses. One company's first lookup pays for the rest.
 *  - `grap_credit_ledger` — every genuinely paid call, so "why did this month
 *    cost 400 credits" has an answer that is not a guess.
 *  - `grap_search_cache` — the finished payload for a company/country/role, so
 *    asking the same question twice is free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grap_verification_cache', function (Blueprint $table) {
            // The address is the key. 191 rather than 255 because this was
            // written for utf8mb4 under the old index limit and there is no
            // reason to widen it now.
            $table->string('email', 191)->primary();
            $table->string('status', 24);
            $table->string('provider', 24);
            $table->timestamp('checked_at')->useCurrent()->index();
        });

        Schema::create('grap_domain_facts', function (Blueprint $table) {
            $table->string('domain', 191)->primary();

            // DNS: free, run first, kills dead domains before any paid call.
            $table->boolean('has_mx')->nullable();
            $table->timestamp('mx_checked_at')->nullable();

            // Catch-all: established once per domain, then never re-paid.
            $table->boolean('is_catchall')->nullable();
            $table->timestamp('catchall_checked_at')->nullable();

            // Proved by a mail server accepting exactly one mailbox.
            $table->string('verified_pattern', 32)->nullable();
            $table->unsignedInteger('pattern_samples')->default(0);
            $table->decimal('pattern_confidence', 4, 3)->nullable();

            /*
             * Read off an address the company published. Deliberately a
             * separate column: `verified_pattern` is read everywhere as "a
             * server confirmed this", and letting a guess share that column is
             * how it gets laundered into a fact one join later.
             */
            $table->string('inferred_pattern', 32)->nullable();
            $table->decimal('inferred_confidence', 4, 3)->nullable();

            $table->timestamp('last_checked_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('grap_credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('provider', 24);
            $table->string('email', 191);
            $table->string('status', 24);
            // What the pipeline was doing: a search, a reveal, a bulk row.
            $table->string('context', 24);
            // The company name or job id, so the row means something later.
            $table->string('reference', 191)->nullable();
            // The naming pattern that produced the address, so "which patterns
            // did my credits actually buy" is answerable.
            $table->string('pattern', 32)->nullable();
            /*
             * Who spent it. Not in GrapUp, which is single-tenant: here the
             * ledger has to answer "whose allowance did this come out of" as
             * well as "what did it cost".
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->index('occurred_at');
            $table->index(['context', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });

        Schema::create('grap_search_cache', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 191);
            $table->string('country', 96)->default('');
            $table->string('target_role', 96)->default('');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['company_name', 'country', 'target_role'], 'grap_search_cache_question');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grap_search_cache');
        Schema::dropIfExists('grap_credit_ledger');
        Schema::dropIfExists('grap_domain_facts');
        Schema::dropIfExists('grap_verification_cache');
    }
};
