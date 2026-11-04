<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_Historico', function (Blueprint $table) {
            $table->bigIncrements('ID');
            $table->string('Contrato');
            $table->string('Fundo');
            $table->longText('Historico');
            $table->string('Fiscal');
        });
    }

    public function down(): void
    {
        // Preserve contract history, including on rollback.
    }
};
