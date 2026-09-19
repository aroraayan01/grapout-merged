<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * From conversation to deal.
 *
 * An enquiry now has a stage, so a company can see where every
 * conversation stands: new, accepted, declined, meeting, quoted, sampling,
 * negotiating, won, lost. A meeting can hang off it, and the outcome the
 * team records after the meeting moves the stage on. An enquiry can also
 * be about an opportunity, not only a product or a page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_enquiries', function (Blueprint $table) {
            $table->foreignId('requirement_id')->nullable()->after('product_id')->constrained('business_requirements')->nullOnDelete();
            $table->string('stage', 16)->default('new')->index()->after('status');
            $table->foreignId('meeting_id')->nullable()->after('conversation_id')->constrained('meetings')->nullOnDelete();
            $table->timestamp('meeting_at')->nullable()->after('meeting_id');
            $table->string('outcome', 24)->nullable()->after('meeting_at');
            $table->text('outcome_note')->nullable()->after('outcome');
            $table->timestamp('outcome_at')->nullable()->after('outcome_note');
            $table->foreignId('handled_by')->nullable()->after('outcome_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_enquiries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handled_by');
            $table->dropConstrainedForeignId('meeting_id');
            $table->dropConstrainedForeignId('requirement_id');
            $table->dropColumn(['stage', 'meeting_at', 'outcome', 'outcome_note', 'outcome_at']);
        });
    }
};
