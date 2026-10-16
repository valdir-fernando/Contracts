<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_service_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->restrictOnDelete();
            $table->foreignId('amendment_id')->nullable()->constrained('contract_amendments')->restrictOnDelete();
            $table->string('number', 64);
            $table->date('issued_on');
            $table->string('deadline', 255);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['contract_id', 'issued_on', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_service_orders');
    }
};
