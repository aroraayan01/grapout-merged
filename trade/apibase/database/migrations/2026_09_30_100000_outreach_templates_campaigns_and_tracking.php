<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outreach grows up: templates written in words with placeholders,
 * campaigns that write to a chosen slice of the list at a chosen time,
 * and every email knowing whether it was opened and clicked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Null: the platform's own, offered to every page. Set: one page's own.
            $table->foreignId('page_id')->nullable()->constrained('business_pages')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('kind', 24)->default('custom'); // invitation | catalogue | custom
            $table->string('subject', 255);
            $table->text('body');
            $table->boolean('with_prices')->default(true);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('outreach_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('template_id')->nullable()->constrained('outreach_templates')->nullOnDelete();
            $table->string('template_kind', 24)->default('invitation'); // used when no template row
            $table->foreignId('mailbox_id')->nullable()->constrained('outreach_mailboxes')->nullOnDelete();
            $table->string('status', 12)->default('draft'); // draft | scheduled | sending | done | paused
            $table->string('filter_status', 16)->nullable();
            $table->string('filter_country', 2)->nullable();
            $table->boolean('with_prices')->default(true);
            $table->string('note', 600)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('opened')->default(0);
            $table->unsignedInteger('clicked')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('outreach_sends', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('mailbox_id')->constrained('outreach_campaigns')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->after('campaign_id')->constrained('outreach_templates')->nullOnDelete();
            $table->timestamp('opened_at')->nullable()->after('sent_at');
            $table->timestamp('clicked_at')->nullable()->after('opened_at');
            $table->unsignedInteger('open_count')->default(0)->after('clicked_at');
        });
    }

    public function down(): void
    {
        Schema::table('outreach_sends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn(['opened_at', 'clicked_at', 'open_count']);
        });
        Schema::dropIfExists('outreach_campaigns');
        Schema::dropIfExists('outreach_templates');
    }
};
