<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ata_delivery_order_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });

        Schema::create('ata_delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ata_id')->constrained('tb_ATA', 'ID');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('sequence');
            $table->string('number', 24);
            $table->uuid('request_id');
            $table->timestamp('created_at');
            $table->unique(['year', 'sequence']);
            $table->unique(['ata_id', 'request_id']);
            $table->index(['ata_id', 'number']);
        });
    }

    public function down(): void
    {
        // Preserve issued numbers and orders.
    }
};
