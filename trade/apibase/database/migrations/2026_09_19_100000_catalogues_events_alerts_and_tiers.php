<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trade shows, saved searches, and the rest of the market's furniture.
 *
 * Events: fairs, exhibitions, buyer meets — posted by members, attended
 * with one tap that also puts the dates in the personal calendar. Saved
 * searches: a buyer's standing question, answered by email whenever new
 * products or requirements arrive that match it.
 *
 * The catalogue PDF, the per-product share, the CSV import and the plan
 * tier need no tables of their own: they read what is already here and
 * the plans' own limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('business_pages')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->enum('kind', ['fair', 'exhibition', 'webinar', 'buyer_meet', 'other'])->default('fair');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('venue', 160)->nullable();
            $table->string('city', 80)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('website', 200)->nullable();
            $table->json('keywords')->nullable();
            $table->enum('status', ['listed', 'hidden'])->default('listed');
            $table->unsignedInteger('attendees_count')->default(0);
            $table->timestamps();
            $table->index(['status', 'starts_on']);
            $table->index('country');
        });

        Schema::create('trade_event_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('trade_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // The copy in the person's own calendar, removed when they withdraw.
            $table->foreignId('calendar_event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->timestamps();
            $table->unique(['event_id', 'user_id']);
        });

        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('kind', ['products', 'requirements'])->default('products');
            $table->string('q', 200)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('category', 80)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_hits')->default(0);
            $table->timestamps();
            $table->index(['active', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('trade_event_attendees');
        Schema::dropIfExists('trade_events');
    }
};
