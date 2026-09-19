<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Book meeting, from Hot Leads.
 *
 * A cohort is an email a person writes once and sends to contacts they found:
 * a subject and a body, with placeholders filled per contact. Each person
 * keeps their own, set up in Settings.
 *
 * Every email sent is kept, so "did this go out, and what did it say" has an
 * answer — sent from GrapOut's address on somebody's behalf, that question
 * will be asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grap_cohorts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('subject', 191);
            $table->text('body');
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('grap_meeting_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cohort_id')->nullable()->constrained('grap_cohorts')->nullOnDelete();
            $table->string('to_email', 191);
            $table->string('contact_name', 191)->nullable();
            $table->string('company_name', 191)->nullable();
            $table->string('subject', 191);
            $table->text('body');
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grap_meeting_emails');
        Schema::dropIfExists('grap_cohorts');
    }
};
