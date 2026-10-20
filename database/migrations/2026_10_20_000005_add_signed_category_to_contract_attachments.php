<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_attachments', function (Blueprint $table) {
            $table->string('signed_category', 40)->nullable()->index();
        });
    }

    public function down(): void
    {
        // Preserve document categorization and history.
    }
};
