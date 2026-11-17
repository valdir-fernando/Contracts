<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tb_ValorFicha_ATA')) {
            return;
        }
        if (Schema::hasTable('tb_ValorFicha_ATA_historico')) {
            throw new RuntimeException('A tabela histórica de fichas da ATA já existe. Concilie os dados sem sobrescrever o histórico.');
        }
        Schema::rename('tb_ValorFicha_ATA', 'tb_ValorFicha_ATA_historico');
    }

    public function down(): void
    {
        // Preserve archived financial history.
    }
};
