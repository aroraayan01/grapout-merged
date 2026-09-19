<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trade door is open.
 *
 * A business page can be read by anybody, and a stranger can ask a page
 * something without an account. That guest enquiry sits in the owner's
 * inbox like any other; the owner answers by email, and the email carries
 * a link that turns the guest into a member and the exchange into a chat.
 *
 * Contacts stay the owner's call: email and phone are shown to the public
 * only when the page says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_pages', function (Blueprint $table) {
            $table->boolean('show_contacts')->default(false)->after('phone');
        });

        Schema::table('business_enquiries', function (Blueprint $table) {
            // A guest has no user row; the enquiry carries who they said they are.
            $table->foreignId('from_user_id')->nullable()->change();
            $table->string('guest_name', 120)->nullable()->after('from_user_id');
            $table->string('guest_company', 160)->nullable()->after('guest_name');
            $table->string('guest_email')->nullable()->after('guest_company');
            $table->char('guest_country', 2)->nullable()->after('guest_email');
            // The secret in the reply email. Possessing it is the guest's credential.
            $table->string('guest_token', 64)->nullable()->unique()->after('guest_country');
            $table->text('owner_reply')->nullable()->after('message');
            $table->timestamp('replied_at')->nullable()->after('owner_reply');
            $table->timestamp('claimed_at')->nullable()->after('replied_at');
        });
    }

    public function down(): void
    {
        Schema::table('business_enquiries', function (Blueprint $table) {
            $table->dropColumn(['guest_name', 'guest_company', 'guest_email', 'guest_country', 'guest_token', 'owner_reply', 'replied_at', 'claimed_at']);
        });
        Schema::table('business_pages', function (Blueprint $table) {
            $table->dropColumn('show_contacts');
        });
    }
};
