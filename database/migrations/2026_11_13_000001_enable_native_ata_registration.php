<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_ATA', function (Blueprint $table) {
            foreach (['CARGO_GESTOR', 'RG_FORNECEDOR', 'DOC_PROF', 'PIS_PASEP'] as $column) {
                $table->text($column)->nullable();
            }
        });
        Schema::table('ata_participant_drafts', function (Blueprint $table) {
            $table->unsignedBigInteger('ata_id')->nullable();
            $table->foreign('ata_id')->references('ID')->on('tb_ATA')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Preserve registered atas, their snapshots and consumed draft identities.
    }
};
