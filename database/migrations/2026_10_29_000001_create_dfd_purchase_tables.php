<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tb_Produtos_Prodata')) {
            Schema::create('tb_Produtos_Prodata', function (Blueprint $table) {
                $table->bigIncrements('ID');
                $table->unsignedBigInteger('ID_Prodata');
                $table->text('Classe');
                $table->text('Descricao');
                $table->string('UND', 100);
                $table->integer('Inativo')->default(0);
            });
        }
        if (! Schema::hasTable('tb_DFD')) {
            Schema::create('tb_DFD', function (Blueprint $table) {
                $table->bigIncrements('ID');
                $table->string('N_DFD', 64);
                $table->date('Data_DFD')->nullable();
                $table->date('Data_Conclusao')->nullable();
                foreach (['Processo_DFD', 'Fundo', 'Necessidade', 'Desricao', 'Prioridade', 'Vigencia', 'Local', 'End_Local', 'Vinculacao', 'Observacoes', 'Requisitante', 'Responsavel', 'Decreto', 'Cargo', 'Categoria', 'Ficha', 'email'] as $column) {
                    $table->text($column)->nullable();
                }
            });
        }
        foreach (['fund_id', 'department_id', 'created_by', 'updated_by', 'legacy_id'] as $column) {
            if (! Schema::hasColumn('tb_DFD', $column)) {
                Schema::table('tb_DFD', fn (Blueprint $table) => $table->unsignedBigInteger($column)->nullable()->index());
            }
        }
        if (! Schema::hasColumn('tb_DFD', 'version')) {
            Schema::table('tb_DFD', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        }
        foreach (['created_at', 'updated_at', 'deleted_at'] as $column) {
            if (! Schema::hasColumn('tb_DFD', $column)) {
                Schema::table('tb_DFD', fn (Blueprint $table) => $table->timestamp($column)->nullable());
            }
        }
        if (! Schema::hasTable('tb_Produtos_DFD')) {
            Schema::create('tb_Produtos_DFD', function (Blueprint $table) {
                $table->bigIncrements('ID');
                $table->unsignedBigInteger('ID_DFD')->index();
                $table->text('N_DFD');
                $table->unsignedBigInteger('ID_PROD')->nullable();
                $table->unsignedInteger('ITEM');
                foreach (['COD_PROD', 'PROD_SIMPLES', 'CARACT', 'PRODUTO', 'UND', 'QTD', 'VL_UNIT', 'VL_TOTAL'] as $column) {
                    $table->text($column)->nullable();
                }
            });
        }
        if (! Schema::hasColumn('tb_Produtos_DFD', 'legacy_id')) {
            Schema::table('tb_Produtos_DFD', fn (Blueprint $table) => $table->unsignedBigInteger('legacy_id')->nullable()->index());
        }
        foreach (['created_at', 'updated_at', 'deleted_at'] as $column) {
            if (! Schema::hasColumn('tb_Produtos_DFD', $column)) {
                Schema::table('tb_Produtos_DFD', fn (Blueprint $table) => $table->timestamp($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        // Preserve DFDs, catalog records and historical products.
    }
};
