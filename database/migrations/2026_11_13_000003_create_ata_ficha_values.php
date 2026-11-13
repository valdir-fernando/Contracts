<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tb_ValorFicha_ATA')) {
            return;
        }
        Schema::create('tb_ValorFicha_ATA', function (Blueprint $table) {
            $table->string('ATA', 32);
            $table->string('Processo', 64);
            $table->string('Ficha', 64);
            $table->decimal('VALOR', 18, 4);
            $table->index(['ATA', 'Processo', 'Ficha'], 'ata_ficha_values_lookup');
        });
    }

    public function down(): void
    {
        // Preserve ata ficha values and their financial history.
    }
};
