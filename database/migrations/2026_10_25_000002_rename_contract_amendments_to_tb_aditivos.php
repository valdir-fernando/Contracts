<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tb_Aditivos')) {
            if (Schema::hasTable('contract_amendments')) {
                throw new RuntimeException('As duas tabelas de aditivos existem; renomeação recusada para preservar os dados.');
            }

            return;
        }
        if (! Schema::hasTable('contract_amendments')) {
            throw new RuntimeException('Tabela de aditivos não encontrada.');
        }
        Schema::rename('contract_amendments', 'tb_Aditivos');
    }

    public function down(): void
    {
        if (Schema::hasTable('contract_amendments')) {
            if (Schema::hasTable('tb_Aditivos')) {
                throw new RuntimeException('As duas tabelas de aditivos existem; reversão recusada.');
            }

            return;
        }
        if (Schema::hasTable('tb_Aditivos')) {
            Schema::rename('tb_Aditivos', 'contract_amendments');
        }
    }
};
