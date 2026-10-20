<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tb_OS')) {
            if (Schema::hasTable('contract_service_orders')) {
                throw new RuntimeException('As duas tabelas de OS existem; renomeação recusada para preservar os dados.');
            }

            return;
        }
        if (! Schema::hasTable('contract_service_orders')) {
            throw new RuntimeException('Tabela de OS não encontrada.');
        }
        Schema::rename('contract_service_orders', 'tb_OS');
    }

    public function down(): void
    {
        if (Schema::hasTable('contract_service_orders')) {
            if (Schema::hasTable('tb_OS')) {
                throw new RuntimeException('As duas tabelas de OS existem; reversão recusada.');
            }

            return;
        }
        if (Schema::hasTable('tb_OS')) {
            Schema::rename('tb_OS', 'contract_service_orders');
        }
    }
};
