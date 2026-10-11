<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('name');
            $table->string('path')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['contract_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_attachments');
    }
};
