<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outreach: a company's own list of buyers and suppliers, written to from
 * a mailbox the GrapOut team keeps, under limits the team sets per page.
 *
 * The list is the company's (contacts). The mailboxes are the platform's.
 * Every email that goes out is a row (sends), so the team can see who is
 * writing to whom, and a recipient can unsubscribe with one click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('label', 80);
            $table->string('from_name', 120);
            $table->string('from_address', 255);
            $table->string('reply_to', 255)->nullable();
            // platform: the server's own mail setup; smtp / ses: this mailbox's own.
            $table->string('mailer', 12)->default('platform');
            $table->string('smtp_host', 255)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 8)->nullable();
            $table->string('smtp_username', 255)->nullable();
            $table->text('smtp_password')->nullable();
            $table->text('ses_key')->nullable();
            $table->text('ses_secret')->nullable();
            $table->string('ses_region', 32)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('daily_limit')->default(500);
            $table->timestamps();
        });

        Schema::create('outreach_contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->string('company_name', 160)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('email', 255);
            $table->string('email_status', 16)->default('unknown'); // unknown | valid | bounced | unsubscribed
            $table->string('mobile', 40)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('source', 16)->default('manual'); // manual | import
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_template', 24)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['page_id', 'email']);
        });

        Schema::create('outreach_sends', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('outreach_contacts')->nullOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained('outreach_mailboxes')->nullOnDelete();
            $table->string('template', 24); // invitation | catalogue
            $table->string('to_email', 255);
            $table->string('subject', 255);
            $table->string('status', 12)->default('queued'); // queued | sent | failed
            $table->text('error')->nullable();
            $table->boolean('with_prices')->default(true);
            $table->string('note', 600)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['page_id', 'created_at']);
        });

        Schema::table('business_pages', function (Blueprint $table) {
            $table->boolean('outreach_enabled')->default(false)->after('meetings_disabled');
            $table->unsignedInteger('outreach_daily_limit')->default(50)->after('outreach_enabled');
            $table->boolean('outreach_bulk_allowed')->default(false)->after('outreach_daily_limit');
            $table->foreignId('outreach_mailbox_id')->nullable()->after('outreach_bulk_allowed')->constrained('outreach_mailboxes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outreach_mailbox_id');
            $table->dropColumn(['outreach_enabled', 'outreach_daily_limit', 'outreach_bulk_allowed']);
        });
        Schema::dropIfExists('outreach_sends');
        Schema::dropIfExists('outreach_contacts');
        Schema::dropIfExists('outreach_mailboxes');
    }
};
