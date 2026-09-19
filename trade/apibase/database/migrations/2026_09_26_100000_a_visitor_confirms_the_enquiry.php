<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A visitor's enquiry reaches the company only once the visitor has proved
 * the address: a short code goes to the email, the visitor types it back.
 * The same code opens their GrapOut account as a temporary password. Until
 * then the enquiry waits unseen — no spam, no invented addresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_enquiries', function (Blueprint $table) {
            $table->string('guest_code', 16)->nullable()->after('guest_token');
            $table->timestamp('guest_code_expires_at')->nullable()->after('guest_code');
            $table->timestamp('confirmed_at')->nullable()->after('guest_code_expires_at');
        });
        // Everything already here was sent before the code existed: it stands as confirmed.
        DB::table('business_enquiries')->whereNull('confirmed_at')->update(['confirmed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('business_enquiries', fn (Blueprint $t) => $t->dropColumn(['guest_code', 'guest_code_expires_at', 'confirmed_at']));
    }
};
