<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tb_Gestor')) {
            if (Schema::hasTable('contract_managers')) {
                throw new RuntimeException('As tabelas contract_managers e tb_Gestor já existem. A renomeação foi recusada para preservar ambas.');
            }

            return;
        }
        if (! Schema::hasTable('contract_managers')) {
            throw new RuntimeException('A tabela contract_managers não foi encontrada para renomeação.');
        }
        Schema::rename('contract_managers', 'tb_Gestor');
    }

    public function down(): void
    {
        if (Schema::hasTable('contract_managers')) {
            if (Schema::hasTable('tb_Gestor')) {
                throw new RuntimeException('As duas tabelas existem. A reversão foi recusada para preservar os dados.');
            }

            return;
        }
        if (Schema::hasTable('tb_Gestor')) {
            Schema::rename('tb_Gestor', 'contract_managers');
        }
    }
};
