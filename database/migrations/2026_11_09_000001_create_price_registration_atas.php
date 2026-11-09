<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ATA', function (Blueprint $table) {
            $table->bigIncrements('ID');
            $table->string('ATA', 32);
            $table->date('DATA_ATA')->nullable();
            $table->string('PROCESSO', 64);
            $table->string('LICITACAO', 100);
            $table->string('N_LICIT', 32);
            foreach (['FORNECEDOR', 'END_FORNECEDOR', 'EMAIL', 'TELEFONE1', 'TELEFONE2', 'SOCIO', 'RG_SOCIO', 'CPF_SOCIO', 'FUNDO', 'END_FUNDO', 'GESTOR', 'CPF_GESTOR', 'RG_GESTOR', 'DECRETO_GESTOR', 'MATRICULA_GESTOR', 'OBJETO DA ATA', 'PERIODO_ATA'] as $column) {
                $table->text($column)->nullable();
            }
            $table->string('CNPJ_FUNDO', 18);
            $table->string('CNPJ_FORNECEDOR', 18)->nullable();
            $table->date('INICIO_ATA')->nullable();
            $table->date('FINAL_ATA')->nullable();
            $table->unsignedBigInteger('fund_id')->nullable()->index();
            $table->json('source_files');
            $table->char('import_hash', 64);
            $table->json('quality_issues');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['CNPJ_FUNDO', 'PROCESSO', 'ATA'], 'ata_identity_unique');
        });
        Schema::create('tb_FUNDOS_ATA', function (Blueprint $table) {
            $table->bigIncrements('ID');
            $table->unsignedBigInteger('ID_ATA');
            $table->foreign('ID_ATA')->references('ID')->on('tb_ATA')->restrictOnDelete();
            $table->string('CNPJ_FUNDO', 18);
            $table->text('FUNDO');
            $table->string('papel', 20);
            $table->unsignedBigInteger('fund_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['ID_ATA', 'CNPJ_FUNDO']);
        });
        Schema::create('tb_PRODUTOS_ATA', function (Blueprint $table) {
            $table->bigIncrements('ID');
            $table->unsignedBigInteger('ID_ATA');
            $table->foreign('ID_ATA')->references('ID')->on('tb_ATA')->restrictOnDelete();
            $table->string('ATA', 32);
            $table->string('PROCESSO', 64);
            $table->string('LICITACAO', 100);
            $table->string('N_LICIT', 32);
            $table->string('ITEM', 32);
            $table->longText('DISCRIMINACAO');
            $table->text('MARCA')->nullable();
            $table->string('UND', 100);
            foreach (['QTD', 'VL_UNIT', 'VL_TOTAL'] as $column) {
                $table->decimal($column, 18, 4);
            }
            $table->json('source_row');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['ID_ATA', 'ITEM']);
        });
    }

    public function down(): void
    {
        // Atas, participantes, produtos e sua proveniência devem ser preservados.
    }
};
