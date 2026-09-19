<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The verified badge asks for documents, plural.
 *
 * One certificate proves a number; two prove a company. Owners keep a
 * document folder on the page — GST, IEC, registration, tax ID — and ask
 * for the badge once at least two are in it. The files live on the
 * private disk and are streamed to super admins only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained('business_pages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 24);
            $table->string('number', 80)->nullable();
            $table->string('path');
            $table->string('original_name', 255);
            $table->string('mime', 80)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_documents');
    }
};
