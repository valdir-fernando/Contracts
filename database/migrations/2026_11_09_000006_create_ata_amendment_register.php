<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tb_ADT_ATA')) {
            Schema::create('tb_ADT_ATA', function (Blueprint $table) {
                $table->bigIncrements('ID');
                $table->string('ATA', 32);
                $table->string('Proc_ATA', 64)->nullable();
                $table->unsignedInteger('ADT');
                $table->date('Data_ADT')->nullable();
                $table->string('Proc_ADT', 64)->nullable();
                $table->string('Tipo_ADT', 100)->nullable();
                $table->date('INICIO')->nullable();
                $table->date('FINAL')->nullable();
                $table->text('Período')->nullable();
                $table->index(['ATA', 'ADT'], 'adt_ata_number_index');
            });
        }
    }

    public function down(): void
    {
        // Preserve the amendment register and any documentary history already recorded.
    }
};
