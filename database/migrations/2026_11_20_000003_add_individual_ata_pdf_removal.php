<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ata_signed_document_copies', fn (Blueprint $table) => $table->string('removed_copy', 8)->nullable());
    }

    public function down(): void
    {
        // Preserve the removal state of documents.
    }
};
