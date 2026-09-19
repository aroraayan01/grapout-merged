<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GrapOut team can open Hot Leads to one account, whatever its plan.
 *
 * Every plan already lists Hot Leads, but Free allows no unlocks at all, so
 * "has Hot Leads" was not the same as "can use Hot Leads". A grant is the
 * second: Grap Buyer, Grap Supplier and Grap Company with no daily limits.
 * Off by default — the plan decides unless an admin says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('grap_leads_granted')->default(false)->after('meetings_disabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('grap_leads_granted'));
    }
};
