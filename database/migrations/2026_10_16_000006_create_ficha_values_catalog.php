<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tb_ValorFicha')) {
            return;
        }
        Schema::create('tb_ValorFicha', function (Blueprint $table) {
            $table->bigIncrements('ID_Ficha');
            foreach (['Fundo', 'Contrato', 'Ficha', 'Valor', 'CPF_CNPJ', 'Proc_Cont', 'Proc_ADT'] as $field) {
                $table->text($field)->nullable();
            }
            $table->integer('N_ADT')->default(0);
        });
    }

    public function down(): void
    {
        // Preserve the source catalog and financial history.
    }
};
