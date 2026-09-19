<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hot Leads grows into a mailing system: mailboxes that also receive
 * (IMAP) and know their DNS standing, groups of mailboxes to rotate
 * across, contact lists, cohorts (saved slices of a list), sequences
 * (a first email and its follow-ups), and an inbox of the replies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_mailbox_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('outreach_mailboxes', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('daily_limit')->constrained('outreach_mailbox_groups')->nullOnDelete();
            $table->string('imap_host')->nullable()->after('group_id');
            $table->unsignedSmallInteger('imap_port')->nullable()->after('imap_host');
            $table->string('imap_encryption', 8)->nullable()->after('imap_port');
            $table->string('imap_username')->nullable()->after('imap_encryption');
            $table->text('imap_password')->nullable()->after('imap_username');
            $table->boolean('imap_self_signed')->default(false)->after('imap_password');
            $table->string('dkim_selector', 64)->nullable()->after('imap_self_signed');
            $table->boolean('dns_spf')->nullable()->after('dkim_selector');
            $table->boolean('dns_dkim')->nullable()->after('dns_spf');
            $table->boolean('dns_dmarc')->nullable()->after('dns_dkim');
            $table->unsignedTinyInteger('dns_score')->nullable()->after('dns_dmarc');
            $table->timestamp('dns_checked_at')->nullable()->after('dns_score');
            $table->boolean('smtp_ok')->nullable()->after('dns_checked_at');
            $table->timestamp('smtp_tested_at')->nullable()->after('smtp_ok');
            $table->boolean('imap_ok')->nullable()->after('smtp_tested_at');
            $table->timestamp('imap_tested_at')->nullable()->after('imap_ok');
            $table->text('last_error')->nullable()->after('imap_tested_at');
            $table->timestamp('inbox_synced_at')->nullable()->after('last_error');
        });

        Schema::create('outreach_lists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['page_id', 'name']);
        });
        Schema::create('outreach_contact_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('outreach_contacts')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('outreach_lists')->cascadeOnDelete();
            $table->unique(['contact_id', 'list_id']);
        });

        Schema::create('outreach_cohorts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->nullable()->constrained('business_pages')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->json('filters');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('outreach_sequences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->nullable()->constrained('business_pages')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->boolean('stop_on_reply')->default(true);
            $table->boolean('stop_on_click')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('outreach_sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained('outreach_sequences')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('template_id')->nullable()->constrained('outreach_templates')->nullOnDelete();
            $table->string('template_kind', 16)->default('invitation');
            $table->unsignedSmallInteger('delay_days')->default(0);
            $table->boolean('only_if_no_reply')->default(true);
            $table->timestamps();
        });

        Schema::table('outreach_campaigns', function (Blueprint $table) {
            $table->foreignId('sequence_id')->nullable()->after('template_id')->constrained('outreach_sequences')->nullOnDelete();
            $table->foreignId('cohort_id')->nullable()->after('sequence_id')->constrained('outreach_cohorts')->nullOnDelete();
            $table->foreignId('list_id')->nullable()->after('cohort_id')->constrained('outreach_lists')->nullOnDelete();
            $table->foreignId('mailbox_group_id')->nullable()->after('mailbox_id')->constrained('outreach_mailbox_groups')->nullOnDelete();
            $table->unsignedInteger('replied')->default(0)->after('clicked');
        });
        Schema::table('outreach_sends', function (Blueprint $table) {
            $table->foreignId('sequence_step_id')->nullable()->after('template_id')->constrained('outreach_sequence_steps')->nullOnDelete();
            $table->unsignedTinyInteger('step_position')->default(1)->after('sequence_step_id');
            $table->timestamp('replied_at')->nullable()->after('clicked_at');
            $table->string('message_id')->nullable()->index()->after('replied_at');
        });
        Schema::table('outreach_contacts', function (Blueprint $table) {
            $table->timestamp('replied_at')->nullable()->after('last_template');
            $table->timestamp('last_reply_at')->nullable()->after('replied_at');
            $table->unsignedInteger('reply_count')->default(0)->after('last_reply_at');
        });
        Schema::table('business_pages', function (Blueprint $table) {
            $table->foreignId('outreach_mailbox_group_id')->nullable()->after('outreach_mailbox_id')->constrained('outreach_mailbox_groups')->nullOnDelete();
        });

        Schema::create('outreach_inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('mailbox_id')->constrained('outreach_mailboxes')->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('business_pages')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('outreach_contacts')->nullOnDelete();
            $table->foreignId('send_id')->nullable()->constrained('outreach_sends')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('outreach_campaigns')->nullOnDelete();
            $table->unsignedBigInteger('imap_uid');
            $table->string('message_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject', 500)->nullable();
            $table->text('snippet')->nullable();
            $table->longText('body_text')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->boolean('is_reply')->default(false);
            $table->timestamps();
            $table->unique(['mailbox_id', 'imap_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_inbox_messages');
        Schema::table('business_pages', fn (Blueprint $t) => $t->dropConstrainedForeignId('outreach_mailbox_group_id'));
        Schema::table('outreach_contacts', fn (Blueprint $t) => $t->dropColumn(['replied_at', 'last_reply_at', 'reply_count']));
        Schema::table('outreach_sends', function (Blueprint $t) {
            $t->dropConstrainedForeignId('sequence_step_id');
            $t->dropColumn(['step_position', 'replied_at', 'message_id']);
        });
        Schema::table('outreach_campaigns', function (Blueprint $t) {
            $t->dropConstrainedForeignId('sequence_id');
            $t->dropConstrainedForeignId('cohort_id');
            $t->dropConstrainedForeignId('list_id');
            $t->dropConstrainedForeignId('mailbox_group_id');
            $t->dropColumn('replied');
        });
        Schema::dropIfExists('outreach_sequence_steps');
        Schema::dropIfExists('outreach_sequences');
        Schema::dropIfExists('outreach_cohorts');
        Schema::dropIfExists('outreach_contact_list');
        Schema::dropIfExists('outreach_lists');
        Schema::table('outreach_mailboxes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('group_id');
            $t->dropColumn(['imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password', 'imap_self_signed', 'dkim_selector', 'dns_spf', 'dns_dkim', 'dns_dmarc', 'dns_score', 'dns_checked_at', 'smtp_ok', 'smtp_tested_at', 'imap_ok', 'imap_tested_at', 'last_error', 'inbox_synced_at']);
        });
        Schema::dropIfExists('outreach_mailbox_groups');
    }
};
