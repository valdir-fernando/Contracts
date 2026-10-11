<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained();
            $table->foreignId('attachment_id')->nullable()->constrained('contract_attachments');
            $table->string('number', 64);
            $table->date('event_date');
            $table->decimal('amount', 18, 4)->nullable();
            $table->string('status', 20)->default('rascunho');
            $table->json('payload');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['contract_id', 'event_date']);
            $table->unique(['contract_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_amendments');
    }
};
