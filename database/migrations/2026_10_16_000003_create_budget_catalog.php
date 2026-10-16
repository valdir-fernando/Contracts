<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tb_Dotacao')) {
            return;
        }
        Schema::create('tb_Dotacao', function (Blueprint $table) {
            $table->bigIncrements('ID_Dotacao');
            foreach (['Orgao', 'Gestao', 'Unidade', 'Funcao', 'SubFunc', 'Programa', 'Projeto', 'Fonte', 'Codigo', 'Ficha', 'Descricao', 'Dotacao', 'Fundo', 'Secretaria'] as $field) {
                $table->text($field)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Existing budget catalog data must be retained.
    }
};
