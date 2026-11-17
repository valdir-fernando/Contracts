<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tb_ATA', 'QTD_ESPECIAL_NOME')) {
            Schema::table('tb_ATA', fn (Blueprint $table) => $table->string('QTD_ESPECIAL_NOME', 60)->nullable());
        }
        if (! Schema::hasColumn('tb_PRODUTOS_ATA', 'QTD_ESPECIAL')) {
            Schema::table('tb_PRODUTOS_ATA', fn (Blueprint $table) => $table->decimal('QTD_ESPECIAL', 18, 4)->nullable());
        }
    }

    public function down(): void
    {
        // Preserve names and quantities already recorded.
    }
};
