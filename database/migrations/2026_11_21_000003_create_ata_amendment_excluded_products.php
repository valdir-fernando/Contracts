<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ata_amendment_excluded_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ata_id')->references('ID')->on('tb_ATA');
            $table->foreignId('amendment_id')->references('ID')->on('tb_ADT_ATA');
            $table->foreignId('product_id')->references('ID')->on('tb_PRODUTOS_ATA');
            $table->unsignedInteger('amendment_number');
            $table->json('product_snapshot');
            $table->timestamp('created_at');
            $table->softDeletes();
            $table->index(['ata_id', 'amendment_id', 'deleted_at'], 'ata_amend_excluded_active_idx');
        });
    }

    public function down(): void
    {
        // Preserve the history of excluded items.
    }
};
