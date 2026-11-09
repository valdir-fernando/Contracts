<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_ATA', fn (Blueprint $table) => $table->json('validity_basis')->nullable());
        Schema::create('tb_ADITIVOS_ATA', function (Blueprint $table) {
            $table->bigIncrements('ID');
            $table->unsignedBigInteger('ID_ATA')->nullable()->index();
            $table->foreign('ID_ATA')->references('ID')->on('tb_ATA')->restrictOnDelete();
            $table->string('ATA', 32)->nullable()->index();
            $table->unsignedInteger('NUMERO')->nullable();
            $table->string('PROCESSO_ORIGEM', 64)->nullable();
            $table->string('PROCESSO_ADITIVO', 64)->nullable();
            $table->string('N_LICIT', 32)->nullable();
            $table->string('CNPJ_FORNECEDOR', 18)->nullable();
            $table->date('INICIO_ADITIVO')->nullable();
            $table->date('FINAL_ADITIVO')->nullable();
            $table->string('source_path', 512)->unique();
            $table->char('source_sha256', 64);
            $table->unsignedBigInteger('source_size');
            $table->string('extraction', 32);
            $table->string('link_status', 32);
            $table->json('issues');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Preserve imported amendments, documentary provenance and validity history.
    }
};
