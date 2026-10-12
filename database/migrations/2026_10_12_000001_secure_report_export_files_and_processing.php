<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->uuid('processing_token')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->index(['status', 'processing_started_at']);
        });
    }

    public function down(): void
    {
        // Keep integrity metadata and history; rollback of code does not erase them.
    }
};
