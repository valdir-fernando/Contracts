<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ata_participant_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('managing_fund_id')->constrained('contract_funds')->restrictOnDelete();
            $table->string('process', 64);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::create('tb_Participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('draft_id')->constrained('ata_participant_drafts')->restrictOnDelete();
            $table->foreignId('fund_id')->constrained('contract_funds')->restrictOnDelete();
            $table->string('ATA', 32)->nullable();
            $table->string('Processo_ATA', 64);
            $table->string('CNPJ_Fundo', 32);
            $table->text('FUNDO');
            $table->string('Tipo_Fundo', 32);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['draft_id', 'fund_id']);
            $table->unique(['draft_id', 'CNPJ_Fundo']);
        });
    }

    public function down(): void
    {
        // Preserve os rascunhos e os participantes já registrados.
    }
};
